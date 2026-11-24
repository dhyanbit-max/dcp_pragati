@php
    // Preview tile for the layer switcher: z6 tile over Assam (x=48, y=27).
    $thumb = fn (?string $url) => $url ? str_replace(['{z}', '{x}', '{y}', '{a-c}', '{a-d}'], ['6', '48', '27', 'a', 'a'], $url) : null;
    $baseLayers = collect(config('dcp.map.base_layers'))->map(fn ($b) => $b + [
        'name' => __($b['label']),
        'thumb' => $b['type'] === 'xyz' ? $thumb($b['url']) : null,
        'labels_thumb' => isset($b['labels_url']) ? $thumb($b['labels_url']) : null,
    ])->values();
    $activeFilters = count(array_filter([$fy, $schemeType, $fundSource, $agency]));
    $config = [
        'worksUrl' => route('map.works'),
        'stateUrl' => route('map.state'),
        'boundariesUrl' => route('map.boundaries', ['level' => '__LEVEL__']),
        'bordersUrl' => route('map.borders', ['level' => '__LEVEL__', 'v' => app(\App\Domain\Gis\MapData::class)->bordersVersion()]),
        'workUrl' => route('map.work', ['work' => '__ID__']),
        'query' => $query,
        'level' => $level,
        'baseLayers' => $baseLayers,
        'defaultBase' => config('dcp.map.default_base'),
        'defaultBaseDark' => config('dcp.map.default_base_dark'),
        'center' => config('dcp.map.center'),
        'zoom' => config('dcp.map.zoom'),
        'staleDays' => config('dcp.monitoring.stale_days'),
        'labels' => [
            'status' => [
                'stalled' => __('Stalled'), 'delayed' => __('Delayed'), 'in_progress' => __('In progress'),
                'not_started' => __('Not started'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'),
            ],
            'stale' => __('No recent update'),
            'staleRing' => __('Dashed ring: no update in :d+ days', ['d' => config('dcp.monitoring.stale_days')]),
            'physical' => __('Physical'),
            'financial' => __('Financial'),
            'lastUpdate' => __('Last update'),
            'never' => __('Never'),
            'open' => __('Open work record'),
            'photos' => __('Site photos'),
            'noPhotos' => __('No photos yet.'),
            'mFromSite' => __('m from site'),
            'outside' => __('Outside site radius'),
            'works' => __('works'),
            'flagged' => __('delayed or stalled'),
            'loading' => __('Loading map…'),
            'failed' => __('The map data could not be loaded. Try again.'),
            'stage' => __('Stage'),
            'district' => __('District'),
            'zoomIn' => __('Zoom in'), 'zoomOut' => __('Zoom out'),
            'fit' => __('Show all works'), 'fullscreen' => __('Full screen'),
            'home' => __('Show all of Assam'), 'highlight' => __('Highlight Assam'), 'stateBorder' => __('State border'),
            'baseMap' => __('Base map'), 'areas' => __('Area shading'),
            'borders' => ['district' => __('District borders'), 'co_district' => __('Co-district borders')],
            'areaLegend' => __('Share of works delayed or stalled'),
            'none' => __('None'),
            'clusterHint' => __('Click to zoom in'),
            'noMatch' => __('No work matches.'),
            'close' => __('Close'),
            'showing' => __('Showing'),
            'of' => __('of'),
            'toggleHint' => __('Click a status to hide or show it'),
        ],
    ];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-2xl font-semibold text-ink">{{ __('Works map') }}</h1>
            <p class="text-sm text-ink-2">{{ __('Every work with a site location. Search, filter, switch to satellite, and click any work for its photos and progress.') }}</p>
        </div>
        @if ($level !== 'none')
            <a href="#area-table" class="text-sm text-accent hover:underline">{{ __('Area figures as a table') }} ↓</a>
        @endif
    </div>

    <div class="map-shell relative h-[calc(100vh-12rem)] min-h-[560px] overflow-hidden rounded-xl border border-line shadow-sm">
        {{-- Map + JS-owned overlays (Livewire must not touch these) --}}
        <div wire:ignore class="absolute inset-0">
            <div id="works-map" data-config='@json($config)' class="absolute inset-0 bg-surface-2" role="application" aria-label="{{ __('Works map') }}"></div>

            {{-- Layer switcher + map controls (top right) --}}
            <div id="map-controls" class="absolute right-3 top-3 z-20 flex flex-col items-end gap-2"></div>

            {{-- Status legend / toggles (bottom left) --}}
            <div id="map-legend" class="map-glass absolute bottom-3 left-3 z-20 max-w-[calc(100%-1.5rem)] p-2.5"></div>

            {{-- Selected work drawer (right) --}}
            <aside id="map-drawer" class="map-glass absolute bottom-3 right-3 top-3 z-30 flex w-[min(23rem,calc(100%-1.5rem))] translate-x-[110%] flex-col overflow-hidden transition-transform duration-300 ease-out" aria-live="polite" aria-hidden="true"></aside>

            <div id="map-tooltip" class="map-glass pointer-events-none absolute z-40 hidden max-w-64 px-2.5 py-1.5 text-xs"></div>
            <div id="map-status" class="map-glass absolute left-1/2 top-3 z-20 hidden -translate-x-1/2 px-3 py-1.5 text-xs text-ink-2" aria-live="polite"></div>
        </div>

        {{-- Search + filters (top left). Filters are Livewire; the search box is JS-owned. --}}
        <div class="absolute left-3 top-3 z-20 flex w-[min(21rem,calc(100%-6rem))] flex-col gap-2" x-data="{ open: false }">
            <div wire:ignore>
                <div class="map-glass relative flex items-center">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="pointer-events-none absolute left-3 size-4 text-ink-3" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <label for="map-search" class="sr-only">{{ __('Search works') }}</label>
                    <input id="map-search" type="search" autocomplete="off" placeholder="{{ __('Search by work code or name') }}"
                           class="w-full rounded-xl border-0 bg-transparent py-2.5 pl-9 pr-3 text-sm text-ink placeholder:text-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40">
                    <ul id="map-search-results" role="listbox" class="map-glass absolute left-0 right-0 top-full mt-1 hidden max-h-72 overflow-y-auto py-1 text-sm"></ul>
                </div>
            </div>

            <button type="button" @click="open = !open" :aria-expanded="open" class="map-glass inline-flex w-fit items-center gap-2 px-3 py-2 text-sm font-medium text-ink hover:bg-surface">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                {{ __('Filters') }}
                @if ($activeFilters)<span class="rounded-full bg-accent px-1.5 text-xs text-on-accent">{{ $activeFilters }}</span>@endif
                <span class="text-ink-3" x-text="open ? '▴' : '▾'"></span>
            </button>

            <div x-show="open" x-transition.origin.top.left x-cloak class="map-glass grid gap-2.5 p-3" @keydown.escape="open = false">
                <div>
                    <label for="m-level" class="label text-xs">{{ __('Areas') }}</label>
                    <select id="m-level" wire:model.live="level" class="field py-1.5">
                        <option value="district">{{ __('Districts') }}</option>
                        <option value="co_district">{{ __('Co-districts') }}</option>
                        <option value="constituency">{{ __('Constituencies') }}</option>
                        <option value="none">{{ __('No areas') }}</option>
                    </select>
                </div>
                <div>
                    <label for="m-fy" class="label text-xs">{{ __('Financial year') }}</label>
                    <select id="m-fy" wire:model.live="fy" class="field py-1.5"><option value="">{{ __('All') }}</option>
                        @foreach ($options['fy'] as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="m-scheme" class="label text-xs">{{ __('Scheme type') }}</label>
                    <select id="m-scheme" wire:model.live="schemeType" class="field py-1.5"><option value="">{{ __('All') }}</option>
                        @foreach ($options['scheme'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="m-fund" class="label text-xs">{{ __('Fund source') }}</label>
                    <select id="m-fund" wire:model.live="fundSource" class="field py-1.5"><option value="">{{ __('All') }}</option>
                        @foreach ($options['fund'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="m-agency" class="label text-xs">{{ __('Implementing agency') }}</label>
                    <select id="m-agency" wire:model.live="agency" class="field py-1.5"><option value="">{{ __('All') }}</option>
                        @foreach ($options['agency'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Accessible table of the same area figures --}}
    @if ($level !== 'none')
        <section id="area-table" class="card overflow-hidden" aria-labelledby="area-table-h">
            <h2 id="area-table-h" class="border-b border-line px-4 py-3 font-semibold text-ink">{{ __('Areas in view') }}</h2>
            <table class="min-w-full divide-y divide-line">
                <thead class="bg-surface-2">
                    <tr><th class="th">{{ __('Area') }}</th><th class="th num">{{ __('Works') }}</th><th class="th num">{{ __('Delayed or stalled') }}</th><th class="th num">{{ __('Share') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($areas as $a)
                        <tr><td class="td">{{ $a['name'] }}</td><td class="td num">{{ $a['works'] }}</td><td class="td num">{{ $a['flagged'] }}</td><td class="td num">{{ number_format($a['flagged_pct'], 1) }}%</td></tr>
                    @empty
                        <tr><td colspan="4" class="td py-6 text-center text-ink-3">{{ __('No works match these filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @vite('resources/js/map.js')
</div>
