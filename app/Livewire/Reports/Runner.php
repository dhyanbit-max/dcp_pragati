<?php

namespace App\Livewire\Reports;

use App\Domain\Access\Permission;
use App\Domain\Reports\ReportRunner;
use App\Models\CoDistrict;
use App\Models\Constituency;
use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\SchemeType;
use App\Models\Work;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Standard MIS reports (docs/09 §2): pick a report and filters, see it on screen, download Excel or PDF. */
#[Layout('components.layouts.app')]
class Runner extends Component
{
    #[Url(as: 'r')]
    public string $code = 'MIS-01';

    #[Url]
    public array $filters = [];

    #[Url]
    public array $params = [];

    public bool $shown = false;

    public function mount(): void
    {
        $this->authorize(Permission::RUN_REPORTS);
        if (! isset(ReportRunner::REPORTS[$this->code])) {
            $this->code = 'MIS-01';
        }
    }

    public function updated($name): void
    {
        if ($name === 'code') {
            $this->params = [];
        }
        $this->shown = false;
    }

    public function show(): void
    {
        $this->authorize(Permission::RUN_REPORTS);
        $this->shown = true;
    }

    public function exportUrl(string $format): string
    {
        return route('reports.export', ['code' => $this->code, 'format' => $format] + ['filters' => array_filter($this->filters), 'params' => $this->params]);
    }

    public function render(ReportRunner $runner)
    {
        $loc = app()->getLocale();
        $districtIds = Work::query()->distinct()->pluck('district_id');

        return view('livewire.reports.runner', [
            'reports' => $runner->all(),
            'report' => $runner->report($this->code),
            'result' => $this->shown ? $runner->run($this->code, $this->filters, $this->params, auth()->user()) : null,
            'options' => [
                'fy' => FinancialYear::orderByDesc('code')->pluck('code', 'id'),
                'district' => District::whereIn('id', $districtIds)->orderBy('name_en')->get()->mapWithKeys(fn ($d) => [$d->id => $d->name($loc)]),
                'co_district' => CoDistrict::whereIn('district_id', $districtIds)
                    ->when($this->filters['district'] ?? null, fn ($q, $d) => $q->where('district_id', $d))
                    ->orderBy('name_en')->get()->mapWithKeys(fn ($d) => [$d->id => $d->name($loc)]),
                'constituency' => Constituency::whereIn('district_id', $districtIds)->orderBy('ac_no')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->ac_no} · {$c->name($loc)}"]),
                'scheme_type' => SchemeType::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
                'fund_source' => FundSource::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
                'agency' => ImplementingAgency::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
            ],
        ])->title(__('Reports'));
    }
}
