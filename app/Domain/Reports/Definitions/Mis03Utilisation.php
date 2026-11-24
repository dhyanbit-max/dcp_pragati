<?php

namespace App\Domain\Reports\Definitions;

use App\Domain\Reports\Column;
use App\Domain\Reports\Report;
use App\Models\AlertPolicy;
use Illuminate\Support\Collection;

/** MIS-03 Utilisation: released vs spent by district, agency or fund source. docs/09 §2. */
class Mis03Utilisation extends Report
{
    public function code(): string
    {
        return 'MIS-03';
    }

    public function title(): string
    {
        return __('Utilisation of released funds');
    }

    public function description(): string
    {
        return __('Released, spent and unspent money, and works holding old unspent releases.');
    }

    public function options(): array
    {
        return ['group_by' => ['district' => __('District'), 'agency' => __('Implementing agency'), 'fund_source' => __('Fund source')]];
    }

    public function columns(array $params): array
    {
        $p = $this->policy();

        return [
            new Column('name', $this->options()['group_by'][$this->groupBy($params)]),
            new Column('works', __('Works'), 'int', true),
            new Column('released', __('Released'), 'money', true),
            new Column('spent', __('Spent'), 'money', true),
            new Column('unspent', __('Unspent'), 'money', true),
            new Column('utilisation', __('Utilisation'), 'pct'),
            new Column('old_unspent', __('Works with releases over :d days and under :u% spent', ['d' => $p['release_age_days'], 'u' => $p['utilisation_below_pct']]), 'int', true),
        ];
    }

    public function rows(array $filters, array $params): Collection
    {
        $loc = app()->getLocale();
        $p = $this->policy();
        [$table, $fk] = match ($this->groupBy($params)) {
            'agency' => ['implementing_agencies', 'implementing_agency_id'],
            'fund_source' => ['fund_sources', 'fund_source_id'],
            default => ['districts', 'district_id'],
        };
        $today = now()->toDateString();

        return $this->status->filtered($filters)->toBase()
            ->join("$table as g", 'g.id', '=', "works.$fk")
            ->groupBy('g.id')
            ->orderBy('g.name_en')
            ->selectRaw("
                COALESCE(g.name_$loc, g.name_en) AS name,
                COUNT(*) AS works,
                SUM(works.released_total) AS released,
                SUM(works.spent_total) AS spent,
                COUNT(*) FILTER (WHERE works.released_total > 0
                    AND works.spent_total * 100.0 / works.released_total < ?
                    AND EXISTS (SELECT 1 FROM fund_releases fr WHERE fr.work_id = works.id AND fr.released_on < ?::date - make_interval(days => ?))) AS old_unspent
            ", [(float) $p['utilisation_below_pct'], $today, (int) $p['release_age_days']])
            ->get()
            ->map(fn ($r) => (array) $r + [
                'unspent' => max(0, $r->released - $r->spent),
                'utilisation' => self::pct($r->spent, $r->released),
            ]);
    }

    public function totals(Collection $rows, array $params): ?array
    {
        $t = parent::totals($rows, $params);

        return $t ? $t + ['name' => __('Total'), 'utilisation' => self::pct($t['spent'], $t['released'])] : null;
    }

    private function groupBy(array $params): string
    {
        return in_array($params['group_by'] ?? null, ['agency', 'fund_source'], true) ? $params['group_by'] : 'district';
    }

    /** Same thresholds as alert A03, so the report and the alerts agree. */
    private function policy(): array
    {
        return (AlertPolicy::where('code', 'A03')->value('params_json') ?? []) + ['release_age_days' => 60, 'utilisation_below_pct' => 50];
    }
}
