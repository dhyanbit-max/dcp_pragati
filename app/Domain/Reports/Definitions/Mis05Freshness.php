<?php

namespace App\Domain\Reports\Definitions;

use App\Domain\Monitoring\WorkStatus;
use App\Domain\Reports\Column;
use App\Domain\Reports\Report;
use Illuminate\Support\Collection;

/** MIS-05 Freshness by district (or co-district within a district): pilot metric M1 (docs/17). */
class Mis05Freshness extends Report
{
    public function code(): string
    {
        return 'MIS-05';
    }

    public function title(): string
    {
        return __('Data freshness');
    }

    public function description(): string
    {
        return __('How recently active works were updated: the pilot\'s main freshness measure.');
    }

    public function columns(array $params): array
    {
        $d = $this->status->staleDays();

        return [
            new Column('name', __('Area')),
            new Column('active', __('Active works'), 'int', true),
            new Column('fresh', __('Updated in last :d days', ['d' => $d]), 'int', true),
            new Column('fresh_pct', __('Share updated'), 'pct'),
            new Column('median_days', __('Median days since update'), 'decimal'),
            new Column('never', __('Never updated'), 'int', true),
        ];
    }

    public function rows(array $filters, array $params): Collection
    {
        $loc = app()->getLocale();
        $byCo = ! empty($filters['district']);
        [$table, $fk] = $byCo ? ['co_districts', 'co_district_id'] : ['districts', 'district_id'];
        $active = "('".implode("','", WorkStatus::ACTIVE)."')";
        $now = now()->toIso8601String();
        $since = 'COALESCE(works.last_accepted_update_at, works.sanction_date::timestamptz, works.created_at)';

        return $this->status->filtered($filters)->toBase()
            ->whereRaw("works.status IN $active")
            ->leftJoin("$table as g", 'g.id', '=', "works.$fk")
            ->groupBy('g.id')
            ->orderByRaw('MIN(g.name_en) NULLS LAST')
            ->selectRaw("
                COALESCE(MIN(g.name_$loc), MIN(g.name_en)) AS name,
                COUNT(*) AS active,
                COUNT(*) FILTER (WHERE works.last_accepted_update_at >= ?::timestamptz - make_interval(days => ?)) AS fresh,
                COUNT(*) FILTER (WHERE works.last_accepted_update_at IS NULL) AS never,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM ?::timestamptz - $since) / 86400) AS median_days
            ", [$now, $this->status->staleDays(), $now])
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name ?? __('Not in a co-district'),
                'active' => (int) $r->active,
                'fresh' => (int) $r->fresh,
                'fresh_pct' => self::pct($r->fresh, $r->active),
                'median_days' => $r->median_days !== null ? round((float) $r->median_days, 1) : null,
                'never' => (int) $r->never,
            ]);
    }

    public function totals(Collection $rows, array $params): ?array
    {
        $t = parent::totals($rows, $params);

        return $t ? $t + ['name' => __('Total'), 'fresh_pct' => self::pct($t['fresh'], $t['active'])] : null;
    }
}
