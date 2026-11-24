<?php

namespace App\Livewire\Map;

use App\Domain\Access\Permission;
use App\Domain\Gis\MapData;
use App\Models\FinancialYear;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\SchemeType;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Works map page (docs/10). The map itself is OpenLayers (resources/js/map.js) inside wire:ignore;
 * this component owns the filters and the accessible area table, and tells the map when filters change.
 */
#[Layout('components.layouts.app')]
class WorksMap extends Component
{
    #[Url]
    public ?int $fy = null;

    #[Url(as: 'scheme')]
    public ?int $schemeType = null;

    #[Url(as: 'fund')]
    public ?int $fundSource = null;

    #[Url]
    public ?int $agency = null;

    #[Url]
    public string $level = 'district';

    public function mount(): void
    {
        $this->authorize(Permission::VIEW_MAP);
        if (! isset(MapData::LEVELS[$this->level]) && $this->level !== 'none') {
            $this->level = 'district';
        }
    }

    public function filters(): array
    {
        return array_filter([
            'fy' => $this->fy,
            'scheme_type' => $this->schemeType,
            'fund_source' => $this->fundSource,
            'agency' => $this->agency,
        ]);
    }

    public function updated(): void
    {
        $this->dispatch('map-filters', query: http_build_query($this->filters()), level: $this->level);
    }

    public function render(MapData $map)
    {
        $loc = app()->getLocale();

        return view('livewire.map.works-map', [
            'areas' => $this->level === 'none' ? collect() : $map->areas($this->level, $this->filters()),
            'query' => http_build_query($this->filters()),
            'options' => [
                'fy' => FinancialYear::orderByDesc('code')->pluck('code', 'id'),
                'scheme' => SchemeType::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
                'fund' => FundSource::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
                'agency' => ImplementingAgency::orderBy('name_en')->get()->mapWithKeys(fn ($o) => [$o->id => $o->name($loc)]),
            ],
        ])->title(__('Works map'));
    }
}
