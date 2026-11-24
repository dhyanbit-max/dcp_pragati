<?php

namespace App\Livewire\Dashboard;

use App\Domain\Gis\MapData;
use App\Domain\Monitoring\WorkStatus;
use App\Models\CoDistrict;
use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\ProgressUpdate;
use App\Models\SchemeType;
use App\Models\Work;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monitoring dashboard (docs/07 §2): filters, KPI tiles, drill-down state → district → co-district → works,
 * and exception lists. All figures come from accepted data, within the viewer's jurisdiction.
 */
#[Layout('components.layouts.app')]
class Overview extends Component
{
    #[Url]
    public ?int $district = null;

    #[Url(as: 'co')]
    public ?string $coDistrict = null; // id or 'none'

    #[Url]
    public ?int $fy = null;

    #[Url(as: 'scheme')]
    public ?int $schemeType = null;

    #[Url(as: 'fund')]
    public ?int $fundSource = null;

    #[Url]
    public ?int $agency = null;

    #[Url]
    public string $exception = 'delayed';

    public function filters(): array
    {
        return [
            'district' => $this->district,
            'co_district' => $this->coDistrict,
            'fy' => $this->fy,
            'scheme_type' => $this->schemeType,
            'fund_source' => $this->fundSource,
            'agency' => $this->agency,
        ];
    }

    public function drill(?int $district = null, ?string $coDistrict = null): void
    {
        $this->district = $district;
        $this->coDistrict = $coDistrict;
    }

    public function clearFilters(): void
    {
        $this->reset('fy', 'schemeType', 'fundSource', 'agency');
    }

    public function render(WorkStatus $status)
    {
        $filters = $this->filters();
        $level = match (true) {
            $this->coDistrict !== null => 'works',
            $this->district !== null => 'co_district',
            default => 'district',
        };

        $rows = $level === 'works'
            ? $status->withFlags($status->filtered($filters))->withSiteCoords()->with(['currentStage', 'latestAcceptedUpdate.submitter:id,name'])->orderBy('works.code')->limit(200)->get()
            : $status->breakdown($filters, $level);

        $exceptionList = $this->exceptionList($status, $filters);
        $mapStats = $this->mapStats($level, $rows);
        $this->dispatch('dashboard-map', ...$mapStats);

        return view('livewire.dashboard.overview', [
            'kpis' => $status->kpis($filters),
            'level' => $level,
            'rows' => $rows,
            'exceptionList' => $exceptionList,
            'mapStats' => $mapStats,
            'staleDays' => $status->staleDays(),
            'stalledDays' => $status->stalledDays(),
            'mismatchThreshold' => $status->mismatchThreshold(),
            'districtModel' => $this->district ? District::find($this->district) : null,
            'coDistrictModel' => is_numeric($this->coDistrict) ? CoDistrict::find($this->coDistrict) : null,
            'options' => [
                'fy' => FinancialYear::orderByDesc('code')->pluck('code', 'id'),
                'scheme' => SchemeType::orderBy('name_en')->get(),
                'fund' => FundSource::orderBy('name_en')->get(),
                'agency' => ImplementingAgency::orderBy('name_en')->get(),
            ],
        ])->title(__('Dashboard'));
    }

    private function exceptionList(WorkStatus $status, array $filters)
    {
        if ($this->exception === 'pending') {
            $days = (int) config('dcp.monitoring.approval_sla_days', 3);

            return ProgressUpdate::query()->pending()
                ->whereIn('work_id', $status->filtered($filters)->select('works.id'))
                ->where('received_at', '<', now()->subDays($days))
                ->with('work:id,code,name_en,name_as')
                ->oldest('received_at')->limit(50)->get();
        }

        $flag = in_array($this->exception, WorkStatus::FLAGS, true) ? $this->exception : 'delayed';
        $q = $status->withFlags($status->filtered($filters));

        return $status->whereFlag($q, $flag)
            ->with(['district:id,name_en,name_as,name_bn', 'currentStage', 'latestAcceptedUpdate.submitter:id,name'])
            ->orderByRaw('COALESCE(works.last_accepted_update_at, works.created_at)')
            ->limit(50)->get();
    }

    /**
     * Figures for the dashboard map, from the same rows as the drill-down table (accepted data, in scope).
     * Shapes come separately from the cached border endpoints; this payload stays small.
     */
    private function mapStats(string $level, $rows): array
    {
        $areas = $level === 'works' ? [] : collect($rows)->filter(fn ($r) => $r['id'] !== null)->map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'works' => (int) $r['works'],
            'physical_pct' => $r['physical_pct'] !== null ? (float) $r['physical_pct'] : null,
            'financial_pct' => $r['financial_pct'] !== null ? (float) $r['financial_pct'] : null,
            'sanctioned' => (int) $r['sanctioned'],
            'delayed' => (int) $r['delayed'],
            'stalled' => (int) $r['stalled'],
            'flagged' => (int) $r['flagged'],
            'stale' => (int) $r['stale'],
        ])->values()->all();

        $works = $level !== 'works' ? [] : collect($rows)->filter(fn (Work $w) => $w->site_lat !== null)->map(fn (Work $w) => [
            'id' => $w->id,
            'code' => $w->code,
            'name' => self::workName($w),
            'lat' => (float) $w->site_lat,
            'lng' => (float) $w->site_lng,
            'display' => MapData::display($w->status, (bool) $w->is_delayed, (bool) $w->is_stalled),
            'physical_pct' => (float) $w->physical_pct,
            'financial_pct' => (float) $w->financial_pct,
        ])->values()->all();

        return [
            'level' => $level,
            'district' => $this->district,
            'coDistrict' => is_numeric($this->coDistrict) ? (int) $this->coDistrict : null,
            'areas' => $areas,
            'works' => $works,
            'worksTotal' => $level === 'works' ? count($rows) : null,
        ];
    }

    /** Used by the view to label a work name in the current language. */
    public static function workName(Work $w): string
    {
        return (app()->getLocale() === 'as' && $w->name_as) ? $w->name_as : $w->name_en;
    }
}
