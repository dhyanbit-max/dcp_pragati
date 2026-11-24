<?php

namespace App\Domain\Reports\Definitions;

use App\Domain\Reports\Column;
use App\Domain\Reports\Report;
use Illuminate\Support\Collection;

/** MIS-06 Approvals pending, by district. docs/09 §2. */
class Mis06ApprovalsPending extends Report
{
    public function code(): string
    {
        return 'MIS-06';
    }

    public function title(): string
    {
        return __('Approvals pending');
    }

    public function description(): string
    {
        return __('Progress updates waiting for DPC approval, and how long the oldest has waited.');
    }

    public function columns(array $params): array
    {
        return [
            new Column('district', __('District')),
            new Column('pending', __('Pending'), 'int', true),
            new Column('flagged', __('Flagged'), 'int', true),
            new Column('overdue', __('Waiting over :d days', ['d' => config('dcp.monitoring.approval_sla_days')]), 'int', true),
            new Column('oldest_days', __('Oldest (days)'), 'int'),
        ];
    }

    public function rows(array $filters, array $params): Collection
    {
        $loc = app()->getLocale();
        $now = now()->toIso8601String();

        return $this->status->filtered($filters)->toBase()
            ->join('progress_updates as pu', 'pu.work_id', '=', 'works.id')
            ->join('districts', 'districts.id', '=', 'works.district_id')
            ->where('pu.status', 'pending_approval')
            ->groupBy('districts.id')
            ->orderBy('districts.name_en')
            ->selectRaw("
                COALESCE(MIN(districts.name_$loc), MIN(districts.name_en)) AS district,
                COUNT(*) AS pending,
                COUNT(*) FILTER (WHERE pu.is_flagged) AS flagged,
                COUNT(*) FILTER (WHERE pu.received_at < ?::timestamptz - make_interval(days => ?)) AS overdue,
                EXTRACT(DAY FROM ?::timestamptz - MIN(pu.received_at))::int AS oldest_days
            ", [$now, (int) config('dcp.monitoring.approval_sla_days', 3), $now])
            ->get()->map(fn ($r) => (array) $r);
    }

    public function totals(Collection $rows, array $params): ?array
    {
        $t = parent::totals($rows, $params);

        return $t ? $t + ['district' => __('Total'), 'oldest_days' => $rows->max('oldest_days')] : null;
    }
}
