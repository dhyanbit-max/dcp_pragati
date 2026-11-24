@props(['points'])
{{--
    Physical % vs financial % over time. One axis (both are % of the work), two series:
    series-1 (blue) physical, series-2 (orange) financial. 2px lines, 8px markers, legend + direct
    end labels in text ink, recessive grid, crosshair tooltip, table view below (dataviz skill).
--}}
@php
    $W = 640; $H = 230; $ml = 40; $mr = 104; $mt = 14; $mb = 30;
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $tMin = min(array_column($points, 't'));
    $tMax = max(array_column($points, 't'));
    if ($tMax - $tMin < 86400) { $tMax = $tMin + 86400; }
    $x = fn ($t) => round($ml + ($t - $tMin) / ($tMax - $tMin) * $pw, 1);
    $y = fn ($v) => round($mt + (1 - min(max($v, 0), 100) / 100) * $ph, 1);
    $path = fn ($key) => collect($points)->map(fn ($p, $i) => ($i ? 'L' : 'M').$x($p['t']).' '.$y($p[$key]))->implode(' ');

    $last = end($points);
    // Direct labels at the line ends, nudged apart if they would collide.
    $ly = ['physical' => $y($last['physical']), 'financial' => $y($last['financial'])];
    if (abs($ly['physical'] - $ly['financial']) < 16) {
        $mid = ($ly['physical'] + $ly['financial']) / 2;
        $up = $last['physical'] >= $last['financial'] ? 'physical' : 'financial';
        $down = $up === 'physical' ? 'financial' : 'physical';
        $ly[$up] = $mid - 8; $ly[$down] = $mid + 8;
    }

    // ~4 date ticks
    $ticks = [];
    for ($i = 0; $i <= 3; $i++) { $ticks[] = $tMin + ($tMax - $tMin) * $i / 3; }

    $jsPoints = collect($points)->map(fn ($p) => [
        'x' => $x($p['t']), 'yp' => $y($p['physical']), 'yf' => $y($p['financial']),
        'date' => $p['date'], 'physical' => $p['physical'], 'financial' => $p['financial'],
    ])->values();
@endphp

<figure class="viz" x-data="{
        pts: @js($jsPoints), i: null, W: {{ $W }},
        move(e) {
            const svg = this.$refs.svg, pt = svg.createSVGPoint();
            pt.x = e.clientX; pt.y = e.clientY;
            const p = pt.matrixTransform(svg.getScreenCTM().inverse());
            let best = 0;
            this.pts.forEach((q, k) => { if (Math.abs(q.x - p.x) < Math.abs(this.pts[best].x - p.x)) best = k; });
            this.i = best;
        },
        get cur() { return this.i === null ? null : this.pts[this.i]; },
        get left() { return this.cur ? (this.cur.x / this.W * 100) : 0; }
    }">
    <div class="mb-2 flex flex-wrap items-center gap-4 text-xs text-ink-2" aria-hidden="true">
        <span class="inline-flex items-center gap-1.5"><svg width="16" height="8"><line x1="0" y1="4" x2="16" y2="4" stroke="var(--series-1)" stroke-width="2"/><circle cx="8" cy="4" r="3" fill="var(--series-1)"/></svg>{{ __('Physical progress') }}</span>
        <span class="inline-flex items-center gap-1.5"><svg width="16" height="8"><line x1="0" y1="4" x2="16" y2="4" stroke="var(--series-2)" stroke-width="2"/><circle cx="8" cy="4" r="3" fill="var(--series-2)"/></svg>{{ __('Financial progress (spent ÷ sanctioned)') }}</span>
    </div>

    <div class="relative">
        <svg x-ref="svg" viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full" role="img"
             aria-label="{{ __('Physical :p% and financial :f% as of :d', ['p' => $last['physical'], 'f' => $last['financial'], 'd' => $last['date']]) }}"
             style="background: var(--chart-surface)">
            {{-- recessive grid + y axis labels --}}
            @foreach ([0, 25, 50, 75, 100] as $g)
                <line x1="{{ $ml }}" x2="{{ $ml + $pw }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" stroke="var(--grid)" stroke-width="1"/>
                <text x="{{ $ml - 6 }}" y="{{ $y($g) + 4 }}" text-anchor="end" font-size="11" fill="var(--text-3)">{{ $g }}%</text>
            @endforeach
            @foreach ($ticks as $t)
                <text x="{{ $x($t) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="11" fill="var(--text-3)">{{ \Carbon\Carbon::createFromTimestamp($t)->format('M Y') }}</text>
            @endforeach

            {{-- crosshair --}}
            <line x-show="cur" :x1="cur?.x" :x2="cur?.x" y1="{{ $mt }}" y2="{{ $mt + $ph }}" stroke="var(--border-strong)" stroke-width="1" stroke-dasharray="3 3"/>

            {{-- series --}}
            <path d="{{ $path('financial') }}" fill="none" stroke="var(--series-2)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
            <path d="{{ $path('physical') }}" fill="none" stroke="var(--series-1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
            @foreach ($points as $p)
                <circle cx="{{ $x($p['t']) }}" cy="{{ $y($p['financial']) }}" r="4" fill="var(--series-2)" stroke="var(--chart-surface)" stroke-width="2"/>
                <circle cx="{{ $x($p['t']) }}" cy="{{ $y($p['physical']) }}" r="4" fill="var(--series-1)" stroke="var(--chart-surface)" stroke-width="2"/>
            @endforeach
            <template x-if="cur">
                <g>
                    <circle :cx="cur.x" :cy="cur.yf" r="6" fill="var(--series-2)" stroke="var(--chart-surface)" stroke-width="2"/>
                    <circle :cx="cur.x" :cy="cur.yp" r="6" fill="var(--series-1)" stroke="var(--chart-surface)" stroke-width="2"/>
                </g>
            </template>

            {{-- direct end labels (text ink, colour carried by the line) --}}
            <text x="{{ $x($last['t']) + 10 }}" y="{{ $ly['physical'] + 4 }}" font-size="12" fill="var(--text-2)">{{ __('Physical') }} {{ $last['physical'] }}%</text>
            <text x="{{ $x($last['t']) + 10 }}" y="{{ $ly['financial'] + 4 }}" font-size="12" fill="var(--text-2)">{{ __('Financial') }} {{ $last['financial'] }}%</text>

            {{-- hit area larger than the marks --}}
            <rect x="{{ $ml }}" y="{{ $mt }}" width="{{ $pw }}" height="{{ $ph }}" fill="transparent"
                  @mousemove="move($event)" @mouseleave="i = null"/>
        </svg>

        <div x-show="cur" x-cloak class="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-md border border-line bg-surface px-3 py-2 text-xs shadow-md"
             :style="`left: ${left}%`">
            <div class="font-medium text-ink" x-text="cur?.date"></div>
            <div class="mt-1 flex items-center gap-1.5 text-ink-2"><span class="size-2 rounded-full" style="background: var(--series-1)"></span>{{ __('Physical') }} <span class="ml-auto pl-3 tabular-nums text-ink" x-text="cur?.physical + '%'"></span></div>
            <div class="flex items-center gap-1.5 text-ink-2"><span class="size-2 rounded-full" style="background: var(--series-2)"></span>{{ __('Financial') }} <span class="ml-auto pl-3 tabular-nums text-ink" x-text="cur?.financial + '%'"></span></div>
        </div>
    </div>

    <details class="mt-2 text-sm">
        <summary class="cursor-pointer text-ink-2 hover:text-ink">{{ __('Show as table') }}</summary>
        <table class="mt-2 min-w-full text-sm">
            <thead><tr><th class="th">{{ __('Date') }}</th><th class="th num">{{ __('Physical') }}</th><th class="th num">{{ __('Financial') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
            @foreach ($points as $p)
                <tr><td class="td">{{ $p['date'] }}</td><td class="td num">{{ $p['physical'] }}%</td><td class="td num">{{ $p['financial'] }}%</td></tr>
            @endforeach
            </tbody>
        </table>
    </details>
</figure>
