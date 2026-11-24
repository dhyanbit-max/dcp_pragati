@php
    use App\Support\Money;
    $ramp = ['#dbe9fb', '#a9cbf3', '#6fa6ea', '#3a82dc', '#1c5cab'];
    $bin = fn (?float $p) => $p === null ? null : min(4, (int) floor($p / 20));
@endphp
<x-layouts.public>
    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-line bg-gradient-to-b from-accent-soft to-surface">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:py-16">
            <div>
                <p class="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium text-ink-2">
                    <span class="size-2 rounded-full bg-[var(--good)]" aria-hidden="true"></span>
                    {{ __('Decentralised Planning schemes of Assam') }}
                </p>
                <h1 class="mt-4 text-3xl font-semibold leading-tight tracking-tight text-ink sm:text-4xl lg:text-5xl">
                    {{ __('See how development works in your district are progressing.') }}
                </h1>
                <p class="mt-4 max-w-xl text-base text-ink-2 sm:text-lg">
                    {{ __('Engineers report progress from the site with geotagged photos. District officers check and approve every update before it counts. If something is wrong with a work near you, tell us.') }}
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    @if ($grievances)
                        <a href="{{ route('grievances.create') }}" class="btn-primary px-5 py-2.5 text-base">
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            {{ __('Raise a grievance') }}
                        </a>
                        <a href="{{ route('grievances.track') }}" class="btn-secondary px-5 py-2.5 text-base">{{ __('Track a grievance') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="btn-primary px-5 py-2.5 text-base">{{ __('Staff sign in') }}</a>
                    @endif
                    <a href="{{ route('manual') }}" class="btn-secondary px-5 py-2.5 text-base">
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/></svg>
                        {{ __('User manual') }}
                    </a>
                </div>
                @if ($stats)
                    <p class="mt-5 text-xs text-ink-3">
                        {{ __('Figures from approved updates only, as of :t.', ['t' => $stats['as_of']->format('j M Y, g:i A')]) }}
                    </p>
                @endif
            </div>

            {{-- Assam map: districts shaded by average physical progress (approved data) --}}
            @if ($map)
                <figure class="relative">
                    <svg viewBox="{{ $map['viewBox'] }}" class="h-auto w-full drop-shadow-sm" role="img"
                         aria-label="{{ $portal ? __('Map of Assam, districts shaded by average physical progress') : __('Map of Assam') }}">
                        <path d="{{ $map['state'] }}" fill="var(--surface)" stroke="none" />
                        @foreach ($map['districts'] as $d)
                            @php $b = $bin($d['physical']); @endphp
                            <path d="{{ $d['path'] }}" fill="{{ $b === null ? 'var(--surface-2)' : $ramp[$b] }}"
                                  stroke="var(--surface)" stroke-width="1.5" vector-effect="non-scaling-stroke"
                                  class="transition-opacity hover:opacity-80">
                                <title>{{ $d['name'] }}@if ($portal && $d['works']) — {{ trans_choice(':n work|:n works', $d['works'], ['n' => $d['works']]) }}, {{ __('average progress :p%', ['p' => $d['physical']]) }}@endif</title>
                            </path>
                        @endforeach
                        <path d="{{ $map['state'] }}" fill="none" stroke="var(--accent)" stroke-width="2" vector-effect="non-scaling-stroke" />
                    </svg>
                    @if ($portal)
                        <figcaption class="mt-2 flex flex-wrap items-center justify-center gap-2 text-xs text-ink-3">
                            <span>{{ __('Average physical progress') }}</span>
                            <span class="inline-flex overflow-hidden rounded border border-line">
                                @foreach ($ramp as $i => $c)<span class="block h-3 w-7" style="background: {{ $c }}" title="{{ $i * 20 }}–{{ ($i + 1) * 20 }}%"></span>@endforeach
                            </span>
                            <span class="tabular-nums">0 → 100%</span>
                        </figcaption>
                    @endif
                </figure>
            @endif
        </div>
    </section>

    {{-- Figures --}}
    @if ($stats)
        <section aria-labelledby="figures-title" class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <h2 id="figures-title" class="text-xl font-semibold text-ink">{{ __('Assam at a glance') }}</h2>
            <dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                @foreach ([
                    [__('Works monitored'), number_format($stats['works']), __('in :n districts', ['n' => $stats['districts']])],
                    [__('Completed'), number_format($stats['completed']), $stats['works'] ? __(':p% of works', ['p' => round($stats['completed'] * 100 / $stats['works'])]) : ''],
                    [__('Ongoing'), number_format($stats['ongoing']), __('with site updates')],
                    [__('Sanctioned'), Money::short($stats['sanctioned']), __('total cost of works')],
                    [__('Spent so far'), Money::short($stats['spent']), $stats['sanctioned'] ? __(':p% of sanctioned', ['p' => round($stats['spent'] * 100 / $stats['sanctioned'])]) : ''],
                    [__('Average progress'), Money::pct($stats['physical']), __('physical, approved')],
                ] as [$label, $value, $hint])
                    <div class="card px-4 py-4">
                        <dt class="text-xs font-medium text-ink-3">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ $value }}</dd>
                        <dd class="mt-0.5 text-xs text-ink-3">{{ $hint }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($stats['schemes'])
                <h3 class="mt-10 text-base font-semibold text-ink">{{ __('Schemes covered') }}</h3>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @php $maxWorks = max(array_column($stats['schemes'], 'works')) ?: 1; @endphp
                    @foreach ($stats['schemes'] as $s)
                        <li class="card p-4">
                            <div class="flex items-start justify-between gap-3">
                                <span class="font-medium text-ink">{{ $s['name'] }}</span>
                                <span class="shrink-0 rounded-full bg-accent-soft px-2 py-0.5 text-xs font-semibold tabular-nums text-accent">{{ trans_choice(':n work|:n works', $s['works'], ['n' => $s['works']]) }}</span>
                            </div>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
                                <div class="h-full rounded-full bg-accent" style="width: {{ round($s['works'] * 100 / $maxWorks) }}%"></div>
                            </div>
                            <div class="mt-2 flex justify-between text-xs text-ink-3">
                                <span>{{ __(':n completed', ['n' => $s['completed']]) }}</span>
                                <span>{{ __('Sanctioned') }} {{ Money::short((int) $s['sanctioned']) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- How it works --}}
    <section aria-labelledby="how-title" class="border-y border-line bg-surface-2">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <h2 id="how-title" class="text-xl font-semibold text-ink">{{ __('How progress is reported') }}</h2>
            <ol class="mt-6 grid gap-4 md:grid-cols-4">
                @foreach ([
                    ['M3 7h4l2-3h6l2 3h4v13H3z M12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', __('Photographed at the site'), __('The engineer records progress on a phone at the work site. Photos carry the GPS location and time.')],
                    ['M9 12l2 2 4-4 M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z', __('Checked automatically'), __('Twelve rules check every update: spending within funds released, photos taken at the site, progress never going back.')],
                    ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M17 11l2 2 4-4', __('Approved by the district'), __('The District Planning Cell reviews each update, and looks closely at flagged ones, before anything is counted.')],
                    ['M3 3v18h18 M7 15l4-4 3 3 5-6', __('Shared with e-Rupantor'), __('Approved figures feed dashboards for district and state officers, and the official record in e-Rupantor.')],
                ] as $i => [$icon, $title, $text])
                    <li class="card relative p-5">
                        <span class="absolute right-4 top-4 text-3xl font-semibold text-line" aria-hidden="true">{{ $i + 1 }}</span>
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-8 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                        <h3 class="mt-3 font-semibold text-ink">{{ $title }}</h3>
                        <p class="mt-1 text-sm text-ink-2">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Grievance call to action --}}
    @if ($grievances)
        <section aria-labelledby="griev-title" class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <div class="grid gap-6 overflow-hidden rounded-2xl bg-accent p-6 text-on-accent sm:p-10 lg:grid-cols-[1.3fr_1fr]">
                <div>
                    <h2 id="griev-title" class="text-2xl font-semibold">{{ __('Something wrong with a work near you?') }}</h2>
                    <p class="mt-2 max-w-xl text-on-accent/85">{{ __('Poor quality, a work left half-done, a safety hazard, or a work that never started. Tell the district office. You get a reference number and SMS updates until it is closed.') }}</p>
                    <ul class="mt-4 space-y-1.5 text-sm text-on-accent/90">
                        <li class="flex gap-2"><span aria-hidden="true">✓</span>{{ __('Takes about 3 minutes. Add a photo if you have one.') }}</li>
                        <li class="flex gap-2"><span aria-hidden="true">✓</span>{{ __('Acknowledged within :a days, answered within :r days.', ['a' => config('dcp.grievances.acknowledge_days'), 'r' => config('dcp.grievances.resolve_days')]) }}</li>
                        <li class="flex gap-2"><span aria-hidden="true">✓</span>{{ __('Only your mobile number is needed. Your name is optional.') }}</li>
                    </ul>
                    <a href="{{ route('grievances.create') }}" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-surface px-5 py-2.5 font-semibold text-accent shadow-sm hover:bg-accent-soft">
                        {{ __('Raise a grievance') }} <span aria-hidden="true">→</span>
                    </a>
                </div>
                <form method="GET" action="{{ route('grievances.track') }}" class="self-center rounded-xl bg-surface p-5 text-ink shadow-lg">
                    <h3 class="font-semibold">{{ __('Track your grievance') }}</h3>
                    <label for="land-ref" class="label mt-3 text-xs">{{ __('Reference number') }}</label>
                    <input id="land-ref" name="ref" required placeholder="GRV-26-000123" class="field uppercase" autocomplete="off">
                    <button type="submit" class="btn-primary mt-3 w-full justify-center">{{ __('Continue') }}</button>
                    <p class="mt-2 text-xs text-ink-3">{{ __('You will be asked for the mobile number you used.') }}</p>
                </form>
            </div>
        </section>
    @endif

    {{-- Privacy --}}
    <section id="privacy" aria-labelledby="privacy-title" class="mx-auto max-w-6xl px-4 pb-14 sm:px-6">
        <h2 id="privacy-title" class="text-base font-semibold text-ink">{{ __('Privacy') }}</h2>
        <p class="mt-2 max-w-3xl text-sm text-ink-2">
            {{ __('The figures on this page are totals; no names, photos or remarks of officers are published. If you raise a grievance, your mobile number (and name, if you give it) is used only to verify you and to send you updates about it. Only the officers handling your grievance can see it, and it is deleted :y years after the grievance is closed.', ['y' => round(config('dcp.grievances.retention_days') / 365)]) }}
        </p>
    </section>
</x-layouts.public>
