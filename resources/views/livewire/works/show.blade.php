@php use App\Support\Money; use App\Livewire\Dashboard\Overview; $loc = app()->getLocale(); @endphp
<div class="space-y-6">
    {{-- Header --}}
    <div>
        <a href="{{ route('works.index') }}" wire:navigate class="text-sm text-ink-3 hover:text-accent">← {{ __('Works') }}</a>
        <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ Overview::workName($w) }}</h1>
                <div class="mt-1 text-sm text-ink-3">
                    {{ $w->code }}
                    @if ($w->erupantor_id) · e-Rupantor {{ $w->erupantor_id }} @endif
                    · {{ $w->schemeType->name($loc) }} · {{ $w->fundSource->name($loc) }} · {{ $w->financialYear->code }}
                </div>
            </div>
            <div class="flex flex-col items-end gap-2">
                <div class="flex flex-wrap gap-1">
                    <x-status-chip :status="$w->status" />
                    @foreach (['delayed', 'stale', 'mismatch'] as $f)
                        @if ($w->{'is_'.$f}) <x-status-chip :status="$f" /> @endif
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-2">
                    @can(\App\Domain\Access\Permission::RECORD_RELEASE)
                        <button wire:click="openReleaseForm" class="btn-secondary">{{ __('Record release') }}</button>
                    @endcan
                    @can(\App\Domain\Access\Permission::SUBMIT_PROGRESS)
                        <a href="{{ route('works.progress', $w) }}" wire:navigate class="btn-primary">{{ __('Record progress') }}</a>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md border border-line bg-good-soft px-4 py-2 text-sm text-good">{{ session('status') }}</div>
    @endif

    @if ($showReleaseForm)
        <form wire:submit="recordRelease" class="card flex flex-wrap items-end gap-3 p-4" novalidate>
            <h2 class="w-full font-semibold text-ink">{{ __('Record a fund release') }}</h2>
            <div class="min-w-40">
                <label for="rel-amt" class="label">{{ __('Amount (₹)') }}</label>
                <input id="rel-amt" type="number" inputmode="numeric" min="1" step="1" wire:model="releaseRupees" class="field tabular-nums">
            </div>
            <div class="min-w-40">
                <label for="rel-date" class="label">{{ __('Released on') }}</label>
                <input id="rel-date" type="date" wire:model="releasedOn" class="field">
            </div>
            <div class="min-w-48 flex-1">
                <label for="rel-order" class="label">{{ __('Order number') }}</label>
                <input id="rel-order" type="text" wire:model="releaseOrderNo" class="field">
            </div>
            <button type="submit" class="btn-primary">{{ __('Save release') }}</button>
            <button type="button" wire:click="$set('showReleaseForm', false)" class="btn-secondary">{{ __('Cancel') }}</button>
            <div class="w-full space-y-1">
                @foreach (['releaseRupees', 'releasedOn', 'releaseOrderNo'] as $f)
                    @error($f)<p class="text-sm text-critical">{{ $message }}</p>@enderror
                @endforeach
            </div>
        </form>
    @endif

    {{-- Facts + financial strip --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <dl class="card grid grid-cols-2 gap-x-4 gap-y-3 p-4 text-sm lg:col-span-1">
            <div><dt class="text-ink-3">{{ __('District') }}</dt><dd class="text-ink">{{ $w->district->name($loc) }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Co-district') }}</dt><dd class="text-ink">{{ $w->coDistrict?->name($loc) ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Constituency') }}</dt><dd class="text-ink">{{ $w->constituency?->name($loc) ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Agency') }}</dt><dd class="text-ink">{{ $w->agency->name($loc) }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Field officer') }}</dt><dd class="text-ink">{{ $w->assignedFieldUser?->name ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Current stage') }}</dt><dd class="text-ink">{{ $w->currentStage?->name($loc) ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Sanctioned on') }}</dt><dd class="text-ink">{{ $w->sanction_date?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Target completion') }}</dt><dd class="text-ink">{{ $w->target_completion_date?->format('d M Y') ?? '—' }}</dd></div>
            <div class="col-span-2"><dt class="text-ink-3">{{ __('Site') }}</dt>
                <dd class="text-ink tabular-nums">{{ $w->site_lat !== null ? number_format($w->site_lat, 5).', '.number_format($w->site_lng, 5).' ('.__('radius :r m', ['r' => $w->site_radius_m]).')' : __('Not set yet: the first approved geotagged update will set it') }}</dd></div>
        </dl>

        <div class="card p-4 lg:col-span-2">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div><div class="text-xs text-ink-3">{{ __('Sanctioned') }}</div><div class="text-lg font-semibold tabular-nums text-ink">{{ Money::short($w->sanctioned_amount) }}</div></div>
                <div><div class="text-xs text-ink-3">{{ __('Released') }}</div><div class="text-lg font-semibold tabular-nums text-ink">{{ Money::short($w->released_total) }}</div></div>
                <div><div class="text-xs text-ink-3">{{ __('Spent') }}</div><div class="text-lg font-semibold tabular-nums text-ink">{{ Money::short($w->spent_total) }}</div></div>
                <div><div class="text-xs text-ink-3">{{ __('Utilisation') }}</div><div class="text-lg font-semibold tabular-nums text-ink">{{ Money::pct($w->utilisationPct(), 1) }}</div></div>
            </div>
            <div class="mt-4">
                @if (count($series) > 1)
                    <x-progress-chart :points="$series" />
                    @if ($w->is_mismatch)
                        <p class="mt-2 text-sm text-serious">{{ __('Spending is more than :t points ahead of physical progress.', ['t' => $mismatchThreshold]) }}</p>
                    @endif
                @else
                    <p class="rounded-md bg-surface-2 px-4 py-8 text-center text-sm text-ink-3">{{ __('No approved progress yet. The chart appears after the first approved update.') }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Timeline --}}
        <section class="card lg:col-span-2" aria-labelledby="tl">
            <h2 id="tl" class="border-b border-line px-4 py-3 font-semibold text-ink">{{ __('Timeline') }}</h2>
            <ol class="divide-y divide-line">
                @forelse ($timeline as $e)
                    @php $m = $e['model']; @endphp
                    <li class="flex gap-4 px-4 py-3">
                        <div class="w-24 shrink-0 text-xs tabular-nums text-ink-3">{{ $e['at']->format('d M Y') }}</div>
                        <div class="min-w-0 flex-1 text-sm">
                            @switch($e['type'])
                                @case('sanction')
                                    <div class="font-medium text-ink">{{ __('Sanctioned') }} {{ Money::inr($m->sanctioned_amount) }}</div>
                                    @if ($m->sanction_order_no)<div class="text-ink-3">{{ __('Order') }} {{ $m->sanction_order_no }}</div>@endif
                                    @break
                                @case('release')
                                    <div class="font-medium text-ink">{{ __('Funds released') }} {{ Money::inr($m->amount) }}</div>
                                    @if ($m->order_no)<div class="text-ink-3">{{ __('Order') }} {{ $m->order_no }}</div>@endif
                                    @break
                                @case('update')
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-ink">{{ $m->stageItem->name($loc) }} · {{ Money::pct($m->physical_pct) }} · {{ __('spent') }} {{ Money::short($m->spent_cumulative) }}</span>
                                        <x-status-chip :status="$m->status" />
                                        @if ($m->is_flagged && $m->status === 'pending_approval') <x-status-chip status="flagged" /> @endif
                                    </div>
                                    <div class="text-ink-3">
                                        {{ __('By :name', ['name' => $m->submitter->name]) }} · {{ $m->photos->count() }} {{ __('photos') }}
                                        @if ($m->reviewer) · {{ __('Reviewed by :name', ['name' => $m->reviewer->name]) }} @endif
                                    </div>
                                    @if ($m->remarks)<p class="mt-1 text-ink-2">“{{ $m->remarks }}”</p>@endif
                                    @if ($m->review_note)<p class="mt-1 text-ink-2"><span class="text-ink-3">{{ __('Review note') }}:</span> {{ $m->review_note }}</p>@endif
                                    @if ($m->ruleResults->isNotEmpty())
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($m->ruleResults as $rr)
                                                <li class="text-xs {{ $rr->outcome === 'block' ? 'text-critical' : 'text-warning' }}">{{ $rr->rule_code }} · {{ __($rr->message_key, array_filter($rr->details_json ?? [], 'is_scalar')) }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @break
                                @case('alert')
                                    <div class="font-medium text-ink">{{ __('Alert') }} {{ $m->alert_code }} · {{ __(ucfirst($m->status)) }}</div>
                                    @break
                            @endswitch
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-ink-3">{{ __('Nothing recorded yet.') }}</li>
                @endforelse
            </ol>
        </section>

        <div class="space-y-6">
            @if ($grievances->isNotEmpty())
                <section class="card" aria-labelledby="gv">
                    <h2 id="gv" class="flex items-center justify-between border-b border-line px-4 py-3 font-semibold text-ink">
                        {{ __('Grievances') }}
                        <span class="text-xs font-normal text-ink-3">{{ __(':n open', ['n' => $grievances->filter->isOpen()->count()]) }}</span>
                    </h2>
                    <ul class="divide-y divide-line">
                        @foreach ($grievances as $gv)
                            <li class="px-4 py-2.5">
                                <a href="{{ route('grievances.show', $gv) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $gv->subject }}</a>
                                <div class="mt-0.5 flex items-center gap-2 text-xs text-ink-3"><span class="font-mono">{{ $gv->ref_no }}</span><x-grievance-status :status="$gv->status" /></div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Photos --}}
            <section class="card" aria-labelledby="ph">
                <h2 id="ph" class="border-b border-line px-4 py-3 font-semibold text-ink">{{ __('Photos') }}</h2>
                @if ($photos->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-ink-3">{{ __('No photos yet.') }}</p>
                @else
                    <ul class="grid grid-cols-2 gap-2 p-3">
                        @foreach ($photos->take(12) as $p)
                            <li>
                                <a href="{{ route('photos.show', $p) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md border border-line">
                                    <img src="{{ route('photos.show', $p) }}" alt="{{ __('Site photo taken :d', ['d' => $p->taken_at?->format('d M Y H:i') ?? '—']) }}" loading="lazy" class="aspect-[4/3] w-full object-cover">
                                </a>
                                <div class="mt-1 flex items-center justify-between text-xs text-ink-3">
                                    <span>{{ $p->taken_at?->format('d M') }}</span>
                                    @if ($p->distance_from_site_m !== null)
                                        <span @class(['tabular-nums', 'font-medium text-warning' => $p->distance_from_site_m > $w->site_radius_m])>{{ __(':m m from site', ['m' => number_format($p->distance_from_site_m)]) }}</span>
                                    @else
                                        <span>{{ __('No location') }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Milestones --}}
            @if ($w->stages->isNotEmpty())
                <section class="card" aria-labelledby="ms">
                    <h2 id="ms" class="border-b border-line px-4 py-3 font-semibold text-ink">{{ __('Milestones') }}</h2>
                    <table class="min-w-full text-sm">
                        <thead><tr><th class="th">{{ __('Stage') }}</th><th class="th">{{ __('Planned') }}</th><th class="th">{{ __('Achieved') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">
                        @foreach ($w->stages->sortBy(fn ($s) => $s->item->seq) as $s)
                            <tr>
                                <td class="td">{{ $s->item->name($loc) }}</td>
                                <td class="td tabular-nums">{{ $s->planned_date?->format('d M Y') ?? '—' }}</td>
                                <td class="td tabular-nums">{{ $s->achieved_date?->format('d M Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        </div>
    </div>
</div>
