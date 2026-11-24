@php use App\Support\Money; use App\Livewire\Dashboard\Overview; $loc = app()->getLocale(); @endphp
<div class="space-y-6">
    {{-- Title + breadcrumb --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <nav aria-label="{{ __('Breadcrumb') }}" class="mb-1 flex flex-wrap items-center gap-1 text-sm text-ink-3">
                <button wire:click="drill" class="hover:text-accent hover:underline">{{ __('Assam') }}</button>
                @if ($districtModel)
                    <span aria-hidden="true">/</span>
                    <button wire:click="drill({{ $districtModel->id }})" class="hover:text-accent hover:underline">{{ $districtModel->name($loc) }}</button>
                @endif
                @if ($coDistrict !== null)
                    <span aria-hidden="true">/</span>
                    <span class="text-ink-2">{{ $coDistrictModel?->name($loc) ?? __('Not in a co-district') }}</span>
                @endif
            </nav>
            <h1 class="text-2xl font-semibold text-ink">{{ __('Monitoring dashboard') }}</h1>
        </div>
        <div wire:loading class="text-sm text-ink-3">{{ __('Updating…') }}</div>
    </div>

    {{-- Filters: one row above everything they control --}}
    <div class="card flex flex-wrap items-end gap-3 p-3">
        <div class="min-w-32">
            <label for="f-fy" class="label text-xs">{{ __('Financial year') }}</label>
            <select id="f-fy" wire:model.live="fy" class="field py-1.5">
                <option value="">{{ __('All') }}</option>
                @foreach ($options['fy'] as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach
            </select>
        </div>
        <div class="min-w-40">
            <label for="f-scheme" class="label text-xs">{{ __('Scheme type') }}</label>
            <select id="f-scheme" wire:model.live="schemeType" class="field py-1.5">
                <option value="">{{ __('All') }}</option>
                @foreach ($options['scheme'] as $o)<option value="{{ $o->id }}">{{ $o->name($loc) }}</option>@endforeach
            </select>
        </div>
        <div class="min-w-40">
            <label for="f-fund" class="label text-xs">{{ __('Fund source') }}</label>
            <select id="f-fund" wire:model.live="fundSource" class="field py-1.5">
                <option value="">{{ __('All') }}</option>
                @foreach ($options['fund'] as $o)<option value="{{ $o->id }}">{{ $o->name($loc) }}</option>@endforeach
            </select>
        </div>
        <div class="min-w-40">
            <label for="f-agency" class="label text-xs">{{ __('Implementing agency') }}</label>
            <select id="f-agency" wire:model.live="agency" class="field py-1.5">
                <option value="">{{ __('All') }}</option>
                @foreach ($options['agency'] as $o)<option value="{{ $o->id }}">{{ $o->name($loc) }}</option>@endforeach
            </select>
        </div>
        @if ($fy || $schemeType || $fundSource || $agency)
            <button wire:click="clearFilters" class="btn-secondary py-1.5">{{ __('Clear filters') }}</button>
        @endif
    </div>

    {{-- KPI tiles --}}
    <section aria-label="{{ __('Key figures') }}" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-kpi :label="__('Works')" :value="number_format($kpis['works'])" :hint="__(':n in progress · :c completed', ['n' => $kpis['in_progress'], 'c' => $kpis['completed']])" />
        <x-kpi :label="__('Sanctioned')" :value="Money::short($kpis['sanctioned'])" :hint="__('Released').' '.Money::short($kpis['released'])" />
        <x-kpi :label="__('Spent')" :value="Money::short($kpis['spent'])" :hint="__('Utilisation').' '.Money::pct($kpis['utilisation'], 1)" />
        <x-kpi :label="__('Median days since update')" :value="$kpis['median_days_since_update'] ?? '—'"
               :hint="__('Target: :d days or fewer', ['d' => $staleDays])"
               :tone="$kpis['median_days_since_update'] !== null && $kpis['median_days_since_update'] > $staleDays ? 'serious' : 'good'" />
        <x-kpi :label="__('Delayed / stalled')" :value="$kpis['delayed'].' / '.$kpis['stalled']" :tone="($kpis['delayed'] + $kpis['stalled']) > 0 ? 'warning' : null"
               :hint="__(':n with spend ahead of progress', ['n' => $kpis['mismatch']])" />
        <x-kpi :label="__('Pending approval')" :value="$kpis['pending_approvals']" :hint="__(':n open alerts', ['n' => $kpis['open_alerts']])" />
    </section>

    {{-- Assam map: the drill-down as a map. JS-owned (wire:ignore); figures arrive with every render via the dashboard-map event. --}}
    @can(\App\Domain\Access\Permission::VIEW_MAP)
        @php
            $bases = collect(config('dcp.map.base_layers'))->keyBy('id');
            $dashMap = [
                'stats' => $mapStats,
                'bordersUrl' => route('map.borders', ['level' => '__LEVEL__', 'v' => app(\App\Domain\Gis\MapData::class)->bordersVersion()]),
                'stateUrl' => route('map.state'),
                'workUrl' => route('works.show', ['work' => '__ID__']),
                'fullMapUrl' => route('map'),
                'center' => config('dcp.map.center'),
                'zoom' => config('dcp.map.zoom'),
                'base' => ['light' => $bases['light'] ?? null, 'dark' => $bases['dark'] ?? null],
                'labels' => [
                    'metrics' => [
                        'works' => __('Works'), 'flagged' => __('Delayed or stalled'), 'physical' => __('Physical progress'),
                        'financial' => __('Financial progress'), 'stale' => __('No recent update'),
                    ],
                    'metricHint' => [
                        'works' => __('Number of works'), 'flagged' => __('Share of works delayed or stalled'),
                        'physical' => __('Average physical progress'), 'financial' => __('Spent ÷ sanctioned'),
                        'stale' => __('Share with no update in :d+ days', ['d' => $staleDays]),
                    ],
                    'status' => [
                        'stalled' => __('Stalled'), 'delayed' => __('Delayed'), 'in_progress' => __('In progress'),
                        'not_started' => __('Not started'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'),
                    ],
                    'works' => __('Works'), 'sanctioned' => __('Sanctioned'), 'physical' => __('Physical'), 'financial' => __('Financial'),
                    'delayed' => __('Delayed'), 'stalled' => __('Stalled'), 'stale' => __('No recent update'),
                    'noData' => __('No works in view'), 'clickToOpen' => __('Click to open'), 'clickToDrill' => __('Click to look inside'),
                    'assam' => __('Assam'), 'reset' => __('Show all of Assam'), 'zoomIn' => __('Zoom in'), 'zoomOut' => __('Zoom out'),
                    'up' => __('Back up one level'), 'ctrlZoom' => __('Use Ctrl + scroll to zoom the map'),
                    'ranked' => __('Ranked by'), 'hoverHint' => __('Point at an area for its figures; click to look inside.'),
                    'noShapes' => __('Area outlines are not loaded yet. Figures are in the table below.'),
                    'worksOnMap' => __(':n of :t works have a site location', ['n' => '__N__', 't' => '__T__']),
                    'openMap' => __('Open full map'),
                ],
            ];
        @endphp
        <section class="card overflow-hidden" aria-labelledby="dash-map-title">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 id="dash-map-title" class="font-semibold text-ink">{{ __('Assam at a glance') }}</h2>
                    <p class="text-xs text-ink-3">{{ __('The same figures as the table below, on the map. Filters above apply.') }}</p>
                </div>
                <div wire:ignore id="dash-map-metrics" role="radiogroup" aria-label="{{ __('Colour areas by') }}" class="flex flex-wrap gap-1 rounded-lg bg-surface-2 p-1"></div>
            </div>
            <div wire:ignore class="grid lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div class="relative h-[26rem] lg:h-[30rem]">
                    <div id="dash-map" data-config='@json($dashMap)' class="absolute inset-0 bg-surface-2" role="img" aria-label="{{ __('Map of Assam coloured by the selected figure') }}"></div>
                    <div id="dash-map-crumbs" class="map-glass absolute left-3 top-3 z-10 flex items-center gap-1 px-2 py-1 text-xs"></div>
                    <div id="dash-map-tools" class="map-glass absolute right-3 top-3 z-10 flex flex-col overflow-hidden p-0"></div>
                    <div id="dash-map-legend" class="map-glass absolute bottom-3 left-3 z-10 px-2.5 py-2 text-[11px]"></div>
                    <div id="dash-map-tip" class="map-glass pointer-events-none absolute z-20 hidden min-w-44 px-3 py-2 text-xs"></div>
                    <div id="dash-map-note" class="map-glass pointer-events-none absolute left-1/2 top-1/2 z-20 hidden -translate-x-1/2 -translate-y-1/2 px-3 py-1.5 text-xs text-ink-2"></div>
                </div>
                <aside id="dash-map-side" class="flex max-h-[30rem] flex-col border-t border-line lg:border-l lg:border-t-0" aria-live="polite"></aside>
            </div>
        </section>
        @vite('resources/js/dashboard-map.js')
    @endcan

    {{-- Drill-down --}}
    <section class="card overflow-hidden" aria-labelledby="drill-title">
        <div class="flex items-center justify-between border-b border-line px-4 py-3">
            <h2 id="drill-title" class="font-semibold text-ink">
                @switch($level)
                    @case('district') {{ __('By district') }} @break
                    @case('co_district') {{ __('By co-district') }} @break
                    @default {{ __('Works') }}
                @endswitch
            </h2>
            <span class="text-xs text-ink-3">{{ __('Physical % is the average of accepted progress; financial % is spent ÷ sanctioned.') }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line">
                <thead class="bg-surface-2">
                @if ($level === 'works')
                    <tr>
                        <th class="th">{{ __('Work') }}</th>
                        <th class="th">{{ __('Stage') }}</th>
                        <th class="th num">{{ __('Physical') }}</th>
                        <th class="th num">{{ __('Financial') }}</th>
                        <th class="th num">{{ __('Last update') }}</th>
                        <th class="th">{{ __('Status') }}</th>
                    </tr>
                @else
                    <tr>
                        <th class="th">{{ $level === 'district' ? __('District') : __('Co-district') }}</th>
                        <th class="th num">{{ __('Works') }}</th>
                        <th class="th num">{{ __('Sanctioned') }}</th>
                        <th class="th num">{{ __('Physical') }}</th>
                        <th class="th num">{{ __('Financial') }}</th>
                        <th class="th num">{{ __('Utilisation') }}</th>
                        <th class="th num">{{ __('Delayed') }}</th>
                        <th class="th num">{{ __('Stalled') }}</th>
                        <th class="th num">{{ __('No update :d+ days', ['d' => $staleDays]) }}</th>
                        <th class="th num">{{ __('Spend ahead') }}</th>
                    </tr>
                @endif
                </thead>
                <tbody class="divide-y divide-line">
                @forelse ($rows as $r)
                    @if ($level === 'works')
                        <tr class="hover:bg-surface-2">
                            <td class="td">
                                <a href="{{ route('works.show', $r) }}" wire:navigate class="font-medium text-accent hover:underline">{{ Overview::workName($r) }}</a>
                                <div class="text-xs text-ink-3">{{ $r->code }}</div>
                            </td>
                            <td class="td text-ink-2">{{ $r->currentStage?->name($loc) ?? '—' }}</td>
                            <td class="td num">{{ Money::pct($r->physical_pct) }}</td>
                            <td class="td num">{{ Money::pct((float) $r->financial_pct) }}</td>
                            <td class="td num text-ink-2"><x-last-update :at="$r->last_accepted_update_at" :by="$r->latestAcceptedUpdate?->submitter?->name" /></td>
                            <td class="td">
                                <div class="flex flex-wrap gap-1">
                                    <x-status-chip :status="$r->status" />
                                    @foreach (['delayed', 'stale', 'mismatch'] as $f)
                                        @if ($r->{'is_'.$f}) <x-status-chip :status="$f" /> @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @else
                        @php $target = $level === 'district' ? "drill({$r['id']})" : "drill({$district}, '".($r['id'] ?? 'none')."')"; @endphp
                        <tr class="cursor-pointer hover:bg-surface-2" wire:click="{{ $target }}">
                            <td class="td">
                                <button wire:click.stop="{{ $target }}" class="font-medium text-accent hover:underline">
                                    {{ $r['name'] ?? __('Not in a co-district') }}
                                </button>
                            </td>
                            <td class="td num">{{ $r['works'] }}</td>
                            <td class="td num">{{ Money::short((int) $r['sanctioned']) }}</td>
                            <td class="td num">{{ Money::pct($r['physical_pct'] !== null ? (float) $r['physical_pct'] : null, 1) }}</td>
                            <td class="td num">{{ Money::pct($r['financial_pct'] !== null ? (float) $r['financial_pct'] : null, 1) }}</td>
                            <td class="td num">{{ Money::pct($r['utilisation'] !== null ? (float) $r['utilisation'] : null, 1) }}</td>
                            <td @class(['td num', 'font-semibold text-warning' => $r['delayed'] > 0])>{{ $r['delayed'] }}</td>
                            <td @class(['td num', 'font-semibold text-critical' => $r['stalled'] > 0])>{{ $r['stalled'] }}</td>
                            <td @class(['td num', 'font-semibold text-serious' => $r['stale'] > 0])>{{ $r['stale'] }}</td>
                            <td @class(['td num', 'font-semibold text-serious' => $r['mismatch'] > 0])>{{ $r['mismatch'] }}</td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="10" class="td py-8 text-center text-ink-3">{{ __('No works match these filters.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Exception lists --}}
    <section class="card overflow-hidden" aria-labelledby="exc-title">
        <div class="border-b border-line px-4 pt-3">
            <h2 id="exc-title" class="font-semibold text-ink">{{ __('Needs attention') }}</h2>
            <div role="tablist" class="-mb-px mt-2 flex gap-4 overflow-x-auto text-sm">
                @foreach ([
                    'delayed' => __('Delayed'),
                    'stalled' => __('Stalled (no update :d days)', ['d' => $stalledDays]),
                    'mismatch' => __('Spend ahead of progress (>:t pts)', ['t' => $mismatchThreshold]),
                    'stale' => __('No update :d+ days', ['d' => $staleDays]),
                    'pending' => __('Approval overdue'),
                ] as $key => $label)
                    <button role="tab" aria-selected="{{ $exception === $key ? 'true' : 'false' }}" wire:click="$set('exception', '{{ $key }}')"
                        @class(['whitespace-nowrap border-b-2 pb-2 font-medium',
                                'border-accent text-accent' => $exception === $key,
                                'border-transparent text-ink-2 hover:text-ink' => $exception !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line">
                <tbody class="divide-y divide-line">
                @forelse ($exceptionList as $item)
                    @if ($exception === 'pending')
                        <tr>
                            <td class="td"><a href="{{ route('works.show', $item->work_id) }}" class="font-medium text-accent hover:underline">{{ $item->work->code }}</a></td>
                            <td class="td text-ink-2">{{ __('Submitted :when', ['when' => $item->received_at->diffForHumans()]) }}</td>
                            <td class="td num">{{ Money::pct($item->physical_pct) }}</td>
                            <td class="td text-right">
                                @can(\App\Domain\Access\Permission::APPROVE_PROGRESS)
                                    <a href="{{ route('approvals') }}" class="text-sm text-accent hover:underline">{{ __('Review') }}</a>
                                @endcan
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td class="td">
                                <a href="{{ route('works.show', $item) }}" wire:navigate class="font-medium text-accent hover:underline">{{ Overview::workName($item) }}</a>
                                <div class="text-xs text-ink-3">{{ $item->code }} · {{ $item->district?->name($loc) }}</div>
                            </td>
                            <td class="td text-ink-2">{{ $item->currentStage?->name($loc) ?? '—' }}</td>
                            <td class="td num">{{ __('Physical') }} {{ Money::pct($item->physical_pct) }}</td>
                            <td class="td num">{{ __('Financial') }} {{ Money::pct((float) $item->financial_pct) }}</td>
                            <td class="td num text-ink-2"><x-last-update :at="$item->last_accepted_update_at" :by="$item->latestAcceptedUpdate?->submitter?->name" :never="__('No update yet')" /></td>
                        </tr>
                    @endif
                @empty
                    <tr><td class="td py-8 text-center text-ink-3">{{ __('Nothing here. Good.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
