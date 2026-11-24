<?php

namespace App\Domain\Progress;

use App\Jobs\EvaluateWorkAlerts;
use App\Models\ProgressUpdate;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkStage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * DPC review: approve, return or reject a pending update. Approval is the only path by which
 * figures reach the work record (and so dashboards, alerts, reports and e-Rupantor).
 */
class ApprovalService
{
    public function approve(ProgressUpdate $update, ?User $reviewer, ?string $note = null): ProgressUpdate
    {
        $this->assertPending($update);

        return DB::transaction(function () use ($update, $reviewer, $note) {
            $work = Work::withoutGlobalScopes()->lockForUpdate()->findOrFail($update->work_id);

            // Re-check monotonicity against anything accepted since submission.
            $latestPct = ProgressUpdate::withoutGlobalScopes()->where('work_id', $work->id)->accepted()->max('physical_pct');
            if ($latestPct !== null && $update->physical_pct < (float) $latestPct) {
                throw new InvalidArgumentException('A later update with higher progress was already accepted; return this one instead.');
            }

            $update->forceFill([
                'status' => ProgressUpdate::STATUS_ACCEPTED,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'erupantor_sync_status' => 'queued',
            ])->save();

            $work->forceFill([
                'current_stage_item_id' => $update->stage_item_id,
                'physical_pct' => $update->physical_pct,
                'spent_total' => $update->spent_cumulative,
                // Updates can be approved out of capture order (offline phones sync late): never move this back.
                'last_accepted_update_at' => $work->last_accepted_update_at && $work->last_accepted_update_at->gt($update->captured_at)
                    ? $work->last_accepted_update_at
                    : $update->captured_at,
                'status' => $update->physical_pct >= 100 ? Work::STATUS_COMPLETED : Work::STATUS_IN_PROGRESS,
            ])->save();

            WorkStage::where('work_id', $work->id)
                ->where('stage_template_item_id', $update->stage_item_id)
                ->whereNull('achieved_date')
                ->update(['achieved_date' => $update->captured_at->toDateString()]);

            // First geotagged accepted update proposes the site (docs/05 §3).
            if (! $work->hasSite()) {
                $photo = $update->photos()->withoutGlobalScopes()->whereNotNull('lat')->orderBy('accuracy_m')->first();
                if ($photo) {
                    $work->setSite($photo->lat, $photo->lng);
                }
            }

            EvaluateWorkAlerts::dispatch($work->id)->afterCommit();

            return $update;
        });
    }

    /** @param list<int> $ids  Only clean (unflagged) pending updates are bulk-approved. */
    public function bulkApprove(array $ids, User $reviewer): int
    {
        $count = 0;
        ProgressUpdate::query()->whereIn('id', $ids)->pending()->where('is_flagged', false)
            ->orderBy('captured_at')->get()
            ->each(function (ProgressUpdate $u) use ($reviewer, &$count) {
                $this->approve($u, $reviewer);
                $count++;
            });

        return $count;
    }

    public function returnToSubmitter(ProgressUpdate $update, User $reviewer, string $note): ProgressUpdate
    {
        return $this->close($update, $reviewer, $note, ProgressUpdate::STATUS_RETURNED);
    }

    public function reject(ProgressUpdate $update, User $reviewer, string $note): ProgressUpdate
    {
        return $this->close($update, $reviewer, $note, ProgressUpdate::STATUS_REJECTED);
    }

    private function close(ProgressUpdate $update, User $reviewer, string $note, string $status): ProgressUpdate
    {
        $this->assertPending($update);
        $update->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        return $update;
    }

    private function assertPending(ProgressUpdate $update): void
    {
        if ($update->status !== ProgressUpdate::STATUS_PENDING) {
            throw new InvalidArgumentException("Update {$update->id} is {$update->status}, not pending approval.");
        }
    }
}
