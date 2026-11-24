<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Definitions\Mis01DistrictAbstract;
use App\Domain\Reports\Definitions\Mis02SchemeWiseStatus;
use App\Domain\Reports\Definitions\Mis03Utilisation;
use App\Domain\Reports\Definitions\Mis04DelayedWorks;
use App\Domain\Reports\Definitions\Mis05Freshness;
use App\Domain\Reports\Definitions\Mis06ApprovalsPending;
use App\Models\CoDistrict;
use App\Models\Constituency;
use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Models\SchemeType;
use App\Models\User;
use InvalidArgumentException;

/**
 * Runs reports and records each run in report_runs (duration feeds pilot metric M4, docs/17).
 * Scope: the authenticated user's jurisdiction (JurisdictionScope on works).
 */
class ReportRunner
{
    public const REPORTS = [
        'MIS-01' => Mis01DistrictAbstract::class,
        'MIS-02' => Mis02SchemeWiseStatus::class,
        'MIS-03' => Mis03Utilisation::class,
        'MIS-04' => Mis04DelayedWorks::class,
        'MIS-05' => Mis05Freshness::class,
        'MIS-06' => Mis06ApprovalsPending::class,
    ];

    public const FILTERS = ['fy', 'district', 'co_district', 'constituency', 'scheme_type', 'fund_source', 'agency', 'status'];

    public function report(string $code): Report
    {
        $class = self::REPORTS[$code] ?? throw new InvalidArgumentException("Unknown report $code");

        return app($class);
    }

    /** @return array<string, Report> */
    public function all(): array
    {
        return array_map(fn ($class) => app($class), self::REPORTS);
    }

    public function run(string $code, array $filters, array $params, User $user, string $format = 'screen'): ReportResult
    {
        $started = hrtime(true);
        $report = $this->report($code);
        $filters = array_intersect_key(array_filter($filters, fn ($v) => $v !== null && $v !== ''), array_flip(self::FILTERS));

        $rows = $report->rows($filters, $params);
        $result = new ReportResult(
            code: $code,
            title: $report->title(),
            columns: $report->columns($params),
            rows: $rows,
            totals: $report->totals($rows, $params),
            filterSummary: $this->describe($filters, $params, $report),
            asOf: now(),
            generatedBy: $user->name,
        );

        $this->record($code, $filters + $params, $user, $format, $started);

        return $result;
    }

    public function record(string $code, array $params, User $user, string $format, int $startedHr, string $type = 'mis'): ReportRun
    {
        $template = ReportTemplate::firstOrCreate(['code' => $code], [
            'name_en' => $code, 'type' => $type, 'definition_json' => [], 'formats' => ['xlsx', 'pdf'],
        ]);

        return ReportRun::create([
            'report_template_id' => $template->id,
            'params_json' => $params,
            'requested_by' => $user->id,
            'status' => 'done',
            'format' => $format === 'screen' ? 'html' : $format,
            'duration_ms' => (int) ((hrtime(true) - $startedHr) / 1_000_000),
        ]);
    }

    /** @return list<string> */
    public function describe(array $filters, array $params = [], ?Report $report = null): array
    {
        $loc = app()->getLocale();
        $name = fn ($model, $id) => $model::find($id)?->name($loc) ?? (string) $id;
        $lines = [];
        foreach ($filters as $k => $v) {
            $lines[] = match ($k) {
                'fy' => __('Financial year').': '.(FinancialYear::find($v)?->code ?? $v),
                'district' => __('District').': '.$name(District::class, $v),
                'co_district' => __('Co-district').': '.($v === 'none' ? __('Not in a co-district') : $name(CoDistrict::class, $v)),
                'constituency' => __('Constituency').': '.collect((array) $v)->map(fn ($id) => ($c = Constituency::find($id)) ? "{$c->ac_no} {$c->name($loc)}" : $id)->implode(', '),
                'scheme_type' => __('Scheme type').': '.$name(SchemeType::class, $v),
                'fund_source' => __('Fund source').': '.$name(FundSource::class, $v),
                'agency' => __('Implementing agency').': '.$name(ImplementingAgency::class, $v),
                'status' => __('Status').': '.__(ucfirst(str_replace('_', ' ', $v))),
                default => "$k: $v",
            };
        }
        foreach ($report?->options() ?? [] as $key => $choices) {
            if (isset($params[$key], $choices[$params[$key]])) {
                $lines[] = __('Grouped by').': '.$choices[$params[$key]];
            }
        }

        return $lines ?: [__('All works in your area')];
    }
}
