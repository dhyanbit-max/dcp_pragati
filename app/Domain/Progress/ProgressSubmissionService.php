<?php

namespace App\Domain\Progress;

use App\Domain\Rules\RuleEngine;
use App\Models\ProgressPhoto;
use App\Models\ProgressUpdate;
use App\Models\User;
use App\Models\Work;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a progress update from any channel, runs the rules engine, and records the result.
 * Idempotent on client_uuid. See docs/05-rules-engine.md §5.
 */
class ProgressSubmissionService
{
    public function __construct(
        private readonly RuleEngine $engine,
        private readonly ContextBuilder $contexts,
        private readonly ApprovalService $approvals,
    ) {}

    public function submit(User $user, ProgressInput $input): SubmissionResult
    {
        if ($existing = ProgressUpdate::withoutGlobalScopes()->where('client_uuid', $input->clientUuid)->first()) {
            return new SubmissionResult($existing->load('ruleResults'), replayed: true);
        }

        // Jurisdiction-scoped lookup: a user cannot submit for a work they cannot see.
        $work = Work::query()->findOrFail($input->workId);

        $photos = ProgressPhoto::withoutGlobalScopes()
            ->whereIn('client_uuid', $input->photoUuids)
            ->where('work_id', $work->id)
            ->whereNull('progress_update_id')
            ->get();
        if ($photos->count() !== count(array_unique($input->photoUuids))) {
            throw new MissingPhotosException(array_values(array_diff($input->photoUuids, $photos->pluck('client_uuid')->all())));
        }

        return DB::transaction(function () use ($user, $input, $work, $photos) {
            $receivedAt = CarbonImmutable::now();
            $ctx = $this->contexts->build($work, $input, $photos, $receivedAt);
            $evaluation = $this->engine->evaluate($ctx, $work->district_id);

            $status = $evaluation->blocked() ? ProgressUpdate::STATUS_REJECTED : ProgressUpdate::STATUS_PENDING;

            $update = ProgressUpdate::create([
                'client_uuid' => $input->clientUuid,
                'work_id' => $work->id,
                'submitted_by' => $user->id,
                'channel' => $input->channel,
                'stage_item_id' => $input->stageItemId,
                'physical_pct' => $input->physicalPct,
                'spent_cumulative' => $input->spentCumulative,
                'remarks' => $input->remarks,
                'captured_at' => $input->capturedAt,
                'received_at' => $receivedAt,
                'device_lat' => $input->deviceLat,
                'device_lng' => $input->deviceLng,
                'device_accuracy_m' => $input->deviceAccuracyM,
                'app_version' => $input->appVersion,
                'form_seconds' => $input->formSeconds,
                'status' => $status,
                'is_flagged' => $evaluation->flagged(),
            ]);

            foreach ($evaluation->results as $r) {
                $update->ruleResults()->create([
                    'rule_code' => $r->code,
                    'outcome' => $r->outcome,
                    'message_key' => $r->messageKey,
                    'details_json' => $r->details ?: null,
                ]);
            }

            // Rejected updates keep their photos unattached so the user can resubmit with them.
            if ($status !== ProgressUpdate::STATUS_REJECTED) {
                ProgressPhoto::withoutGlobalScopes()->whereIn('id', $photos->pluck('id'))
                    ->update(['progress_update_id' => $update->id]);
            }

            if ($status === ProgressUpdate::STATUS_PENDING && ! $update->is_flagged && config('dcp.approval.auto_accept_clean')) {
                $this->approvals->approve($update, null, 'auto-accepted (clean)');
            }

            return new SubmissionResult($update->fresh('ruleResults'), replayed: false);
        });
    }
}
