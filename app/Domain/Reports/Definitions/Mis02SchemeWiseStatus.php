<?php

namespace App\Domain\Reports\Definitions;

use App\Domain\Reports\Column;
use App\Domain\Reports\Report;
use App\Models\Work;
use Illuminate\Support\Collection;

/** MIS-02 Scheme-wise status: one row per work. docs/09 §2. */
class Mis02SchemeWiseStatus extends Report
{
    public function code(): string
    {
        return 'MIS-02';
    }

    public function title(): string
    {
        return __('Scheme-wise status');
    }

    public function description(): string
    {
        return __('Every work with its money, stage, progress, last update and status.');
    }

    public function columns(array $params): array
    {
        return [
            new Column('code', __('Code')),
            new Column('erupantor_id', __('e-Rupantor ID')),
            new Column('name', __('Work')),
            new Column('district', __('District')),
            new Column('agency', __('Agency')),
            new Column('sanction_date', __('Sanctioned on'), 'date'),
            new Column('sanctioned', __('Sanctioned'), 'money', true),
            new Column('released', __('Released'), 'money', true),
            new Column('spent', __('Spent'), 'money', true),
            new Column('stage', __('Stage')),
            new Column('physical_pct', __('Physical'), 'pct'),
            new Column('financial_pct', __('Financial'), 'pct'),
            new Column('last_update', __('Last update'), 'date'),
            new Column('status', __('Status')),
        ];
    }

    public function rows(array $filters, array $params): Collection
    {
        $loc = app()->getLocale();

        return $this->status->withFlags($this->status->filtered($filters))
            ->with(['district', 'agency', 'currentStage'])
            ->orderBy('works.district_id')->orderBy('works.code')
            ->get()
            ->map(fn (Work $w) => [
                'code' => $w->code,
                'erupantor_id' => $w->erupantor_id,
                'name' => ($loc === 'as' && $w->name_as) ? $w->name_as : $w->name_en,
                'district' => $w->district->name($loc),
                'agency' => $w->agency->name($loc),
                'sanction_date' => $w->sanction_date?->format('d-m-Y'),
                'sanctioned' => $w->sanctioned_amount,
                'released' => $w->released_total,
                'spent' => $w->spent_total,
                'stage' => $w->currentStage?->name($loc),
                'physical_pct' => $w->physical_pct,
                'financial_pct' => (float) $w->financial_pct,
                'last_update' => $w->last_accepted_update_at?->format('d-m-Y'),
                'status' => collect([__(ucfirst(str_replace('_', ' ', $w->status)))])
                    ->when($w->is_delayed, fn ($c) => $c->push(__('Delayed')))
                    ->when($w->is_stale, fn ($c) => $c->push(__('No recent update')))
                    ->implode(', '),
            ]);
    }

    public function totals(Collection $rows, array $params): ?array
    {
        $t = parent::totals($rows, $params);

        return $t ? $t + ['code' => __('Total'), 'name' => trans_choice(':count work|:count works', $rows->count(), ['count' => $rows->count()])] : null;
    }
}
