<?php

namespace App\Domain\Reports\Definitions;

use App\Domain\Access\JurisdictionScope;
use App\Domain\Reports\Column;
use App\Domain\Reports\Report;
use App\Models\Alert;
use App\Models\Work;
use App\Models\WorkStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** MIS-04 Delayed works: what is overdue, by how long, and how far its alert has escalated. docs/09 §2. */
class Mis04DelayedWorks extends Report
{
    public function code(): string
    {
        return 'MIS-04';
    }

    public function title(): string
    {
        return __('Delayed works');
    }

    public function description(): string
    {
        return __('Works past a planned milestone or their completion date, with days overdue.');
    }

    public function columns(array $params): array
    {
        return [
            new Column('code', __('Code')),
            new Column('name', __('Work')),
            new Column('district', __('District')),
            new Column('overdue', __('Overdue')),
            new Column('due', __('Was due'), 'date'),
            new Column('days_overdue', __('Days overdue'), 'int'),
            new Column('physical_pct', __('Physical'), 'pct'),
            new Column('last_update', __('Last update'), 'date'),
            new Column('alert_level', __('Escalated to')),
        ];
    }

    public function rows(array $filters, array $params): Collection
    {
        $loc = app()->getLocale();
        $grace = $this->status->milestoneGraceDays();
        $today = now()->startOfDay();

        $works = $this->status->whereFlag($this->status->withFlags($this->status->filtered($filters)), 'delayed')
            ->with('district')->orderBy('works.code')->get();

        $missed = WorkStage::with('item')->whereIn('work_id', $works->pluck('id'))->whereNull('achieved_date')
            ->where('planned_date', '<', $today->copy()->subDays($grace))->orderBy('planned_date')->get()->groupBy('work_id');
        $alerts = Alert::withoutGlobalScope(JurisdictionScope::class)->whereIn('work_id', $works->pluck('id'))
            ->where('alert_code', 'A02')->whereIn('status', ['open', 'acknowledged'])->pluck('level', 'work_id');
        $levels = [0 => __('Owner'), 1 => __('DPC'), 2 => __('Deputy Commissioner'), 3 => __('DCP Division')];

        return $works->map(function (Work $w) use ($missed, $alerts, $levels, $loc, $today) {
            $stage = $missed->get($w->id)?->first();
            [$what, $due] = $stage
                ? [$stage->item->name($loc), $stage->planned_date]
                : [__('Completion'), $w->target_completion_date];

            return [
                'code' => $w->code,
                'name' => ($loc === 'as' && $w->name_as) ? $w->name_as : $w->name_en,
                'district' => $w->district->name($loc),
                'overdue' => $what,
                'due' => $due?->format('d-m-Y'),
                'days_overdue' => $due ? (int) Carbon::parse($due)->diffInDays($today) : null,
                'physical_pct' => $w->physical_pct,
                'last_update' => $w->last_accepted_update_at?->format('d-m-Y'),
                'alert_level' => $alerts->has($w->id) ? $levels[$alerts[$w->id]] : '—',
            ];
        })->sortByDesc('days_overdue')->values();
    }
}
