<?php

namespace App\Domain\Alerts;

use App\Domain\Access\JurisdictionScope;
use App\Domain\Monitoring\WorkStatus;
use App\Models\Alert;
use App\Models\AlertPolicy;
use App\Models\StageTemplateItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opens and auto-closes alerts A01–A05 (docs/08 §1). Reads accepted data only.
 * One open (or acknowledged) alert per work per code; a manually resolved alert
 * does not reopen within `resolve_snooze_days`. New alerts notify level 0.
 */
class AlertEvaluator
{
    public function __construct(
        private readonly AlertNotifier $notifier,
        private readonly WorkStatus $status,
    ) {}

    /**
     * @param  list<int>|null  $workIds  null = every work
     * @return array{opened: int, closed: int}
     */
    public function evaluate(?array $workIds = null): array
    {
        $opened = $closed = 0;
        $policies = AlertPolicy::where('active', true)->get()->keyBy('code');

        foreach (AlertCode::ALL as $code) {
            $candidates = isset($policies[$code])
                ? $this->candidates($code, $policies[$code]->params_json ?? [], $workIds)
                : collect();

            [$o, $c] = $this->sync($code, $candidates, $workIds);
            $opened += $o;
            $closed += $c;
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    /** @return Collection<int, array> work_id => details */
    private function candidates(string $code, array $p, ?array $workIds): Collection
    {
        $active = "('".implode("','", WorkStatus::ACTIVE)."')";
        // Application clock, not the database clock, so schedules and tests agree.
        $now = now()->toIso8601String();
        $today = now()->setTimezone(config('app.timezone'))->toDateString();
        $base = fn (): Builder => DB::table('works')->whereNull('works.deleted_at')
            ->when($workIds !== null, fn ($q) => $q->whereIn('works.id', $workIds));

        return match ($code) {
            AlertCode::NO_UPDATE => $base()
                ->whereRaw("works.status IN $active")
                ->whereRaw('COALESCE(works.last_accepted_update_at, works.sanction_date::timestamptz, works.created_at) < ?::timestamptz - make_interval(days => ?)', [$now, (int) ($p['alert_days'] ?? 10)])
                ->selectRaw('works.id, EXTRACT(DAY FROM ?::timestamptz - COALESCE(works.last_accepted_update_at, works.sanction_date::timestamptz, works.created_at))::int AS days', [$now])
                ->get()->mapWithKeys(fn ($r) => [$r->id => ['days' => $r->days]]),

            AlertCode::MISSED_MILESTONE => $this->missedMilestones($base()->whereRaw("works.status IN $active"), (int) ($p['grace_days'] ?? 7), $today),

            AlertCode::UNSPENT_RELEASE => $base()
                ->where('works.status', '!=', 'cancelled')
                ->where('works.released_total', '>', 0)
                ->whereRaw('works.spent_total * 100.0 / works.released_total < ?', [(float) ($p['utilisation_below_pct'] ?? 50)])
                ->whereExists(fn ($q) => $q->from('fund_releases')->whereColumn('fund_releases.work_id', 'works.id')
                    ->whereRaw('fund_releases.released_on < ?::date - make_interval(days => ?)', [$today, (int) ($p['release_age_days'] ?? 60)]))
                ->selectRaw('works.id, ROUND(works.spent_total * 100.0 / works.released_total, 1) AS utilisation, works.released_total')
                ->get()->mapWithKeys(fn ($r) => [$r->id => ['utilisation_pct' => (float) $r->utilisation, 'released' => (int) $r->released_total]]),

            AlertCode::SPEND_MISMATCH => $base()
                ->whereRaw("works.status IN $active")
                ->where('works.sanctioned_amount', '>', 0)
                ->whereRaw('(works.spent_total * 100.0 / works.sanctioned_amount) - works.physical_pct > ?', [$this->status->mismatchThreshold()])
                ->selectRaw('works.id, ROUND(works.spent_total * 100.0 / works.sanctioned_amount, 1) AS financial, works.physical_pct')
                ->get()->mapWithKeys(fn ($r) => [$r->id => ['financial_pct' => (float) $r->financial, 'physical_pct' => (float) $r->physical_pct]]),

            AlertCode::APPROVAL_OVERDUE => $base()
                ->join('progress_updates as pu', 'pu.work_id', '=', 'works.id')
                ->where('pu.status', 'pending_approval')
                ->whereRaw('pu.received_at < ?::timestamptz - make_interval(days => ?)', [$now, (int) ($p['pending_days'] ?? 3)])
                ->groupBy('works.id')
                ->selectRaw('works.id, COUNT(*) AS pending, EXTRACT(DAY FROM ?::timestamptz - MIN(pu.received_at))::int AS oldest_days', [$now])
                ->get()->mapWithKeys(fn ($r) => [$r->id => ['pending' => (int) $r->pending, 'days' => (int) $r->oldest_days]]),
        };
    }

    private function missedMilestones(Builder $works, int $grace, string $today): Collection
    {
        $rows = $works->select('works.id', 'works.target_completion_date')
            ->where(function ($q) use ($grace, $today) {
                $q->whereRaw('works.target_completion_date < ?::date - make_interval(days => ?)', [$today, $grace])
                    ->orWhereExists(fn ($s) => $s->from('work_stages')->whereColumn('work_stages.work_id', 'works.id')
                        ->whereNull('work_stages.achieved_date')
                        ->whereRaw('work_stages.planned_date < ?::date - make_interval(days => ?)', [$today, $grace]));
            })->get();

        $stages = DB::table('work_stages')->whereIn('work_id', $rows->pluck('id'))->whereNull('achieved_date')
            ->whereRaw('planned_date < ?::date - make_interval(days => ?)', [$today, $grace])
            ->orderBy('planned_date')->get()->groupBy('work_id');
        $items = StageTemplateItem::whereIn('id', $stages->flatten()->pluck('stage_template_item_id'))->get()->keyBy('id');

        return $rows->mapWithKeys(function ($w) use ($stages, $items) {
            $first = $stages->get($w->id)?->first();

            return [$w->id => $first
                ? ['stage_item_id' => $first->stage_template_item_id, 'stage' => $items[$first->stage_template_item_id]->name_en ?? '', 'due' => $first->planned_date]
                : ['stage' => 'Completion', 'due' => $w->target_completion_date]];
        });
    }

    /** @return array{0:int, 1:int} opened, closed */
    private function sync(string $code, Collection $candidates, ?array $workIds): array
    {
        $now = now();
        $open = Alert::withoutGlobalScope(JurisdictionScope::class)
            ->where('alert_code', $code)
            ->whereIn('status', [Alert::STATUS_OPEN, Alert::STATUS_ACKNOWLEDGED])
            ->when($workIds !== null, fn ($q) => $q->whereIn('work_id', $workIds))
            ->get()->keyBy('work_id');

        $closed = 0;
        foreach ($open as $workId => $alert) {
            if (! $candidates->has($workId)) {
                $alert->update(['status' => Alert::STATUS_AUTO_CLOSED, 'resolved_at' => $now, 'resolution_note' => 'Condition cleared']);
                $closed++;
            } else {
                $alert->update(['details_json' => $candidates[$workId]]); // keep figures current
            }
        }

        $snoozed = Alert::withoutGlobalScope(JurisdictionScope::class)
            ->where('alert_code', $code)->where('status', Alert::STATUS_RESOLVED)
            ->where('resolved_at', '>', $now->copy()->subDays((int) config('dcp.alerts.resolve_snooze_days', 7)))
            ->whereIn('work_id', $candidates->keys())
            ->pluck('work_id')->flip();

        $opened = 0;
        foreach ($candidates as $workId => $details) {
            if ($open->has($workId) || $snoozed->has($workId)) {
                continue;
            }
            $alert = Alert::create([
                'work_id' => $workId,
                'alert_code' => $code,
                'status' => Alert::STATUS_OPEN,
                'level' => 0,
                'details_json' => $details,
                'opened_at' => $now,
            ]);
            $this->notifier->notify($alert, 0);
            $opened++;
        }

        return [$opened, $closed];
    }
}
