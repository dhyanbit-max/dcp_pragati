<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Monitoring\WorkStatus;
use App\Domain\Rules\RuleEngine;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\ProgressUpdate;
use App\Models\StageTemplateItem;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Field app pull sync (docs/12-api-spec.md §2). Everything is jurisdiction-scoped. */
class SyncController extends Controller
{
    public function pull(Request $request, RuleEngine $engine): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);
        $since = $request->filled('since') ? Carbon::parse($request->input('since'))->setTimezone(config('app.timezone')) : null;
        $now = now();

        $works = Work::query()->withSiteCoords()
            ->when($since, fn ($q) => $q->where('works.updated_at', '>', $since))
            ->orderBy('works.id')
            ->get();

        $templateIds = Work::query()->distinct()->pluck('stage_template_id');
        $stageItems = StageTemplateItem::whereIn('stage_template_id', $templateIds)
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
            ->orderBy('stage_template_id')->orderBy('seq')->get();

        $statuses = ProgressUpdate::query()
            ->where('submitted_by', $request->user()->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
            ->get(['client_uuid', 'status', 'review_note', 'updated_at']);

        $alerts = Alert::query()
            ->whereIn('status', [Alert::STATUS_OPEN, Alert::STATUS_ACKNOWLEDGED])
            ->get(['id', 'work_id', 'alert_code', 'status', 'level', 'opened_at', 'details_json']);

        $rules = collect($engine->definitions())->map(fn ($byScope, $code) => [
            'code' => $code,
            'severity' => $byScope[0]['severity'] ?? null,
            'params' => $byScope[0]['params'] ?? [],
            'district_overrides' => collect($byScope)->except(0)->all(),
        ])->values();

        return response()->json([
            'cursor' => $now->toIso8601String(),
            'works' => $works->map(fn (Work $w) => [
                'id' => $w->id,
                'erupantor_id' => $w->erupantor_id,
                'code' => $w->code,
                'name' => ['en' => $w->name_en, 'as' => $w->name_as],
                'district_id' => $w->district_id,
                'co_district_id' => $w->co_district_id,
                'stage_template_id' => $w->stage_template_id,
                'site' => $w->site_lat !== null ? ['lat' => (float) $w->site_lat, 'lng' => (float) $w->site_lng] : null,
                'site_radius_m' => $w->site_radius_m,
                'sanctioned' => $w->sanctioned_amount,
                'released' => $w->released_total,
                'spent' => $w->spent_total,
                'stage_item_id' => $w->current_stage_item_id,
                'physical_pct' => $w->physical_pct,
                'status' => $w->status,
                'last_accepted_update_at' => $w->last_accepted_update_at?->toIso8601String(),
            ]),
            'stage_items' => $stageItems->map(fn ($s) => [
                'id' => $s->id,
                'stage_template_id' => $s->stage_template_id,
                'seq' => $s->seq,
                'name' => ['en' => $s->name_en, 'as' => $s->name_as, 'bn' => $s->name_bn],
                'min_pct' => $s->min_pct,
                'max_pct' => $s->max_pct,
            ]),
            'update_statuses' => $statuses,
            'alerts' => $alerts,
            'rule_config' => $rules,
            // Thresholds the app shows to users (e.g. the "due for update" badge); same source as the dashboard.
            'app_config' => ['stale_days' => app(WorkStatus::class)->staleDays()],
        ]);
    }
}
