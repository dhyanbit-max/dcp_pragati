<?php

namespace App\Domain\Reports\Definitions;

use App\Domain\Reports\Column;
use App\Domain\Reports\Report;
use Illuminate\Support\Collection;

/** MIS-01 District abstract: district × scheme type (or fund source). docs/09 §2. */
class Mis01DistrictAbstract extends Report
{
    public function code(): string
    {
        return 'MIS-01';
    }

    public function title(): string
    {
        return __('District abstract');
    }

    public function description(): string
    {
        return __('Works, money and status by district and scheme type or fund source.');
    }

    public function options(): array
    {
        return ['group_by' => ['scheme_type' => __('Scheme type'), 'fund_source' => __('Fund source')]];
    }

    public function columns(array $params): array
    {
        return [
            new Column('district', __('District')),
            new Column('group', $this->options()['group_by'][$this->groupBy($params)]),
            new Column('works', __('Works'), 'int', true),
            new Column('sanctioned', __('Sanctioned'), 'money', true),
            new Column('released', __('Released'), 'money', true),
            new Column('spent', __('Spent'), 'money', true),
            new Column('utilisation', __('Utilisation'), 'pct'),
            new Column('completed', __('Completed'), 'int', true),
            new Column('in_progress', __('In progress'), 'int', true),
            new Column('delayed', __('Delayed'), 'int', true),
            new Column('stalled', __('Stalled'), 'int', true),
        ];
    }

    public function rows(array $filters, array $params): Collection
    {
        $loc = app()->getLocale();
        $f = $this->status->flagSql();
        $table = $this->groupBy($params) === 'fund_source' ? 'fund_sources' : 'scheme_types';
        $fk = $table === 'fund_sources' ? 'fund_source_id' : 'scheme_type_id';

        return $this->status->filtered($filters)->toBase()
            ->join('districts', 'districts.id', '=', 'works.district_id')
            ->join($table.' as g', 'g.id', '=', "works.$fk")
            ->groupBy('districts.id', 'g.id')
            ->orderBy('districts.name_en')->orderBy('g.name_en')
            ->selectRaw("
                COALESCE(districts.name_$loc, districts.name_en) AS district,
                COALESCE(g.name_$loc, g.name_en) AS \"group\",
                COUNT(*) AS works,
                SUM(works.sanctioned_amount) AS sanctioned,
                SUM(works.released_total) AS released,
                SUM(works.spent_total) AS spent,
                COUNT(*) FILTER (WHERE works.status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE works.status = 'in_progress') AS in_progress,
                COUNT(*) FILTER (WHERE {$f['delayed']}) AS delayed,
                COUNT(*) FILTER (WHERE {$f['stalled']}) AS stalled
            ")->get()
            ->map(fn ($r) => (array) $r + ['utilisation' => self::pct($r->spent, $r->released)]);
    }

    public function totals(Collection $rows, array $params): ?array
    {
        $t = parent::totals($rows, $params);

        return $t ? $t + ['district' => __('Total'), 'utilisation' => self::pct($t['spent'], $t['released'])] : null;
    }

    private function groupBy(array $params): string
    {
        return ($params['group_by'] ?? null) === 'fund_source' ? 'fund_source' : 'scheme_type';
    }
}
