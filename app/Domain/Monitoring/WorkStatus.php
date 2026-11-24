<?php

namespace App\Domain\Monitoring;

use App\Models\ProgressUpdate;
use App\Models\RuleDefinition;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Status definitions for dashboards (docs/07 §2). Every figure is computed from accepted data only:
 * works' physical_pct / spent_total change only on DPC approval.
 *
 * Delayed  = active, and past target completion or a planned milestone (+ grace) not yet achieved
 * Stalled  = in progress, no accepted update for `stalled_days`
 * Stale    = active, no accepted update for `stale_days` (pilot freshness target)
 * Mismatch = financial % − physical % above the R07 threshold
 */
class WorkStatus
{
    public const ACTIVE = [Work::STATUS_NOT_STARTED, Work::STATUS_IN_PROGRESS, Work::STATUS_STALLED];

    public const FLAGS = ['delayed', 'stalled', 'stale', 'mismatch'];

    public function staleDays(): int
    {
        return (int) config('dcp.monitoring.stale_days', 7);
    }

    public function stalledDays(): int
    {
        return (int) config('dcp.monitoring.stalled_days', 60);
    }

    public function milestoneGraceDays(): int
    {
        return (int) config('dcp.monitoring.milestone_grace_days', 7);
    }

    public function mismatchThreshold(): float
    {
        $def = RuleDefinition::where('code', 'R07')->whereNull('scope_district_id')->first();

        return (float) ($def?->params_json['threshold_pts'] ?? 25);
    }

    /** SQL boolean expressions, keyed by flag, against the `works` table. */
    public function flagSql(): array
    {
        $active = "works.status IN ('".implode("','", self::ACTIVE)."')";
        $lastUpdate = 'COALESCE(works.last_accepted_update_at, works.sanction_date::timestamptz, works.created_at)';
        $financial = 'CASE WHEN works.sanctioned_amount > 0 THEN works.spent_total * 100.0 / works.sanctioned_amount ELSE 0 END';

        return [
            'delayed' => "($active AND (works.target_completion_date < CURRENT_DATE OR EXISTS (
                SELECT 1 FROM work_stages ws WHERE ws.work_id = works.id AND ws.achieved_date IS NULL
                AND ws.planned_date < CURRENT_DATE - INTERVAL '{$this->milestoneGraceDays()} days')))",
            'stalled' => "(works.status IN ('in_progress','stalled') AND $lastUpdate < NOW() - INTERVAL '{$this->stalledDays()} days')",
            'stale' => "($active AND $lastUpdate < NOW() - INTERVAL '{$this->staleDays()} days')",
            'mismatch' => "($active AND ($financial - works.physical_pct) > {$this->mismatchThreshold()})",
        ];
    }

    /** Apply dashboard filters; the jurisdiction scope is applied by the model. */
    public function filtered(array $filters): Builder
    {
        return Work::query()
            ->when($filters['district'] ?? null, fn ($q, $v) => $q->where('works.district_id', $v))
            ->when($filters['co_district'] ?? null, fn ($q, $v) => $v === 'none'
                ? $q->whereNull('works.co_district_id')
                : $q->where('works.co_district_id', $v))
            ->when($filters['fy'] ?? null, fn ($q, $v) => $q->where('works.financial_year_id', $v))
            ->when($filters['scheme_type'] ?? null, fn ($q, $v) => $q->where('works.scheme_type_id', $v))
            ->when($filters['fund_source'] ?? null, fn ($q, $v) => $q->where('works.fund_source_id', $v))
            ->when($filters['agency'] ?? null, fn ($q, $v) => $q->where('works.implementing_agency_id', $v))
            ->when($filters['constituency'] ?? null, fn ($q, $v) => $q->whereIn('works.constituency_id', (array) $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('works.status', $v));
    }

    /** Add `is_<flag>` boolean columns and `financial_pct` / `days_since_update` to a works query. */
    public function withFlags(Builder $query): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select('works.*');
        }
        foreach ($this->flagSql() as $flag => $sql) {
            $query->addSelect(DB::raw("$sql AS is_$flag"));
        }

        return $query->addSelect([
            DB::raw('CASE WHEN works.sanctioned_amount > 0 THEN ROUND(works.spent_total * 100.0 / works.sanctioned_amount, 2) ELSE 0 END AS financial_pct'),
            DB::raw('EXTRACT(DAY FROM NOW() - COALESCE(works.last_accepted_update_at, works.sanction_date::timestamptz, works.created_at))::int AS days_since_update'),
        ]);
    }

    public function whereFlag(Builder $query, string $flag): Builder
    {
        return $query->whereRaw($this->flagSql()[$flag]);
    }

    /** Headline figures for KPI tiles. */
    public function kpis(array $filters): array
    {
        $f = $this->flagSql();
        $row = $this->filtered($filters)->toBase()->selectRaw("
            COUNT(*) AS works,
            COUNT(*) FILTER (WHERE works.status = 'in_progress') AS in_progress,
            COUNT(*) FILTER (WHERE works.status = 'completed') AS completed,
            COUNT(*) FILTER (WHERE {$f['delayed']}) AS delayed,
            COUNT(*) FILTER (WHERE {$f['stalled']}) AS stalled,
            COUNT(*) FILTER (WHERE {$f['stale']}) AS stale,
            COUNT(*) FILTER (WHERE {$f['mismatch']}) AS mismatch,
            COALESCE(SUM(works.sanctioned_amount), 0) AS sanctioned,
            COALESCE(SUM(works.released_total), 0) AS released,
            COALESCE(SUM(works.spent_total), 0) AS spent,
            PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM NOW() - COALESCE(works.last_accepted_update_at, works.sanction_date::timestamptz, works.created_at)) / 86400)
                FILTER (WHERE works.status IN ('".implode("','", self::ACTIVE)."')) AS median_days_since_update
        ")->first();

        $workIds = $this->filtered($filters)->select('works.id');

        return [
            'works' => (int) $row->works,
            'in_progress' => (int) $row->in_progress,
            'completed' => (int) $row->completed,
            'delayed' => (int) $row->delayed,
            'stalled' => (int) $row->stalled,
            'stale' => (int) $row->stale,
            'mismatch' => (int) $row->mismatch,
            'sanctioned' => (int) $row->sanctioned,
            'released' => (int) $row->released,
            'spent' => (int) $row->spent,
            'utilisation' => $row->released > 0 ? round($row->spent * 100 / $row->released, 1) : null,
            'median_days_since_update' => $row->median_days_since_update !== null ? round((float) $row->median_days_since_update, 1) : null,
            'pending_approvals' => ProgressUpdate::query()->pending()->whereIn('work_id', $workIds)->count(),
            'open_alerts' => DB::table('alerts')->whereIn('status', ['open', 'acknowledged'])->whereIn('work_id', $workIds)->count(),
        ];
    }

    /**
     * Drill-down rows: by district (state view), by co-district (district view).
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(array $filters, string $level): array
    {
        $f = $this->flagSql();
        [$key, $join, $label] = match ($level) {
            'district' => ['works.district_id', 'districts', 'districts.name_'.$this->locale()],
            'co_district' => ['works.co_district_id', 'co_districts', 'co_districts.name_'.$this->locale()],
        };

        $rows = $this->filtered($filters)->toBase()
            ->leftJoin($join, "$join.id", '=', $key)
            ->groupBy($key, $label, "$join.name_en")
            ->orderByRaw("$join.name_en NULLS LAST")
            ->selectRaw("
                $key AS id,
                COALESCE($label, $join.name_en) AS name,
                COUNT(*) AS works,
                ROUND(AVG(works.physical_pct), 1) AS physical_pct,
                CASE WHEN SUM(works.sanctioned_amount) > 0 THEN ROUND(SUM(works.spent_total) * 100.0 / SUM(works.sanctioned_amount), 1) END AS financial_pct,
                CASE WHEN SUM(works.released_total) > 0 THEN ROUND(SUM(works.spent_total) * 100.0 / SUM(works.released_total), 1) END AS utilisation,
                SUM(works.sanctioned_amount) AS sanctioned,
                COUNT(*) FILTER (WHERE {$f['delayed']}) AS delayed,
                COUNT(*) FILTER (WHERE {$f['stalled']}) AS stalled,
                COUNT(*) FILTER (WHERE {$f['delayed']} OR {$f['stalled']}) AS flagged,
                COUNT(*) FILTER (WHERE {$f['stale']}) AS stale,
                COUNT(*) FILTER (WHERE {$f['mismatch']}) AS mismatch
            ")->get();

        return $rows->map(fn ($r) => (array) $r)->all();
    }

    private function locale(): string
    {
        return in_array(app()->getLocale(), ['en', 'as', 'bn'], true) ? app()->getLocale() : 'en';
    }
}
