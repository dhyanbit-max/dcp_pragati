@php use App\Support\Money; use App\Livewire\Dashboard\Overview; $loc = app()->getLocale(); @endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-ink">{{ __('Approvals') }}</h1>
            <p class="text-sm text-ink-2">{{ __('Approved updates change the work record, dashboards and reports. Nothing reaches them before approval.') }}</p>
        </div>
        @if ($tab === 'clean' && count($selected))
            <button wire:click="bulkApprove" wire:confirm="{{ __('Approve the selected updates?') }}" class="btn-primary">
                {{ __('Approve selected (:n)', ['n' => count($selected)]) }}
            </button>
        @endif
    </div>

    @if ($flash)
        <div role="status" class="rounded-md border border-line bg-good-soft px-4 py-2 text-sm text-good">{{ $flash }}</div>
    @endif

    <div role="tablist" class="flex gap-4 border-b border-line text-sm">
        @foreach (['flagged' => __('Flagged'), 'clean' => __('Clean')] as $key => $label)
            <button role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                @class(['-mb-px inline-flex items-center gap-1.5 border-b-2 pb-2 font-medium',
                        'border-accent text-accent' => $tab === $key,
                        'border-transparent text-ink-2 hover:text-ink' => $tab !== $key])>
                {{ $label }} <span class="rounded-full bg-surface-2 px-1.5 text-xs tabular-nums text-ink-2">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-5">
        {{-- Queue --}}
        <div class="card overflow-hidden lg:col-span-3">
            <ul class="divide-y divide-line">
                @forelse ($items as $u)
                    <li @class(['flex gap-3 px-4 py-3', 'bg-accent-soft' => $reviewing === $u->id])>
                        @if ($tab === 'clean')
                            <input type="checkbox" wire:model.live="selected" value="{{ $u->id }}" class="mt-1 size-4 accent-[var(--accent)]"
                                   aria-label="{{ __('Select :code', ['code' => $u->work->code]) }}">
                        @endif
                        <button type="button" wire:click="review({{ $u->id }})" class="min-w-0 flex-1 text-left">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium text-ink">{{ Overview::workName($u->work) }}</span>
                                <span class="text-xs text-ink-3">{{ $u->received_at->diffForHumans() }}</span>
                            </div>
                            <div class="text-xs text-ink-3">{{ $u->work->code }} · {{ __('by :name', ['name' => $u->submitter->name]) }}</div>
                            <div class="mt-1 text-sm tabular-nums text-ink-2">
                                {{ Money::pct($u->work->physical_pct) }} → <span class="font-medium text-ink">{{ Money::pct($u->physical_pct) }}</span>
                                · {{ Money::short($u->work->spent_total) }} → <span class="font-medium text-ink">{{ Money::short($u->spent_cumulative) }}</span>
                                · {{ $u->stageItem->name($loc) }} · {{ $u->photos->count() }} {{ __('photos') }}
                            </div>
                            @if ($u->ruleResults->isNotEmpty())
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($u->ruleResults as $rr)
                                        <span class="chip bg-warning-soft text-warning">{{ $rr->rule_code }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-ink-3">{{ $tab === 'flagged' ? __('No flagged updates.') : __('No clean updates waiting.') }}</li>
                @endforelse
            </ul>
        </div>

        {{-- Review panel --}}
        <aside class="lg:col-span-2" aria-live="polite">
            @if ($current)
                <div class="card sticky top-4 space-y-4 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="font-semibold text-ink">{{ Overview::workName($current->work) }}</h2>
                            <a href="{{ route('works.show', $current->work_id) }}" class="text-xs text-accent hover:underline">{{ __('Open work record') }}</a>
                        </div>
                        <button wire:click="closeReview" class="rounded p-1 text-ink-3 hover:bg-surface-2" aria-label="{{ __('Close') }}">✕</button>
                    </div>

                    <dl class="grid grid-cols-3 gap-2 text-sm">
                        <div></div><dt class="text-xs text-ink-3">{{ __('Approved now') }}</dt><dt class="text-xs text-ink-3">{{ __('Submitted') }}</dt>
                        <dt class="text-ink-3">{{ __('Stage') }}</dt><dd>{{ $current->work->currentStage?->name($loc) ?? '—' }}</dd><dd class="font-medium">{{ $current->stageItem->name($loc) }}</dd>
                        <dt class="text-ink-3">{{ __('Physical') }}</dt><dd class="tabular-nums">{{ Money::pct($current->work->physical_pct) }}</dd><dd class="font-medium tabular-nums">{{ Money::pct($current->physical_pct) }}</dd>
                        <dt class="text-ink-3">{{ __('Spent') }}</dt><dd class="tabular-nums">{{ Money::short($current->work->spent_total) }}</dd><dd class="font-medium tabular-nums">{{ Money::short($current->spent_cumulative) }}</dd>
                    </dl>

                    @if ($current->remarks)<p class="text-sm text-ink-2">“{{ $current->remarks }}”</p>@endif

                    @if ($current->ruleResults->isNotEmpty())
                        <ul class="space-y-1 rounded-md bg-warning-soft p-3 text-sm text-warning">
                            @foreach ($current->ruleResults as $rr)
                                <li><span class="font-semibold">{{ $rr->rule_code }}</span> · {{ __($rr->message_key, array_filter($rr->details_json ?? [], 'is_scalar')) }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($current->photos->isNotEmpty())
                        <ul class="grid grid-cols-2 gap-2">
                            @foreach ($current->photos as $p)
                                <li>
                                    <a href="{{ route('photos.show', $p) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md border border-line">
                                        <img src="{{ route('photos.show', $p) }}" alt="{{ __('Site photo') }}" class="aspect-[4/3] w-full object-cover">
                                    </a>
                                    <div class="mt-0.5 text-xs tabular-nums {{ $p->distance_from_site_m !== null && $p->distance_from_site_m > $current->work->site_radius_m ? 'font-medium text-warning' : 'text-ink-3' }}">
                                        {{ $p->distance_from_site_m !== null ? __(':m m from site', ['m' => number_format($p->distance_from_site_m)]) : __('No location') }}
                                        · {{ $p->taken_at?->format('d M H:i') }}
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div>
                        <label for="note" class="label">{{ $current->is_flagged ? __('Note (required)') : __('Note') }}</label>
                        <textarea id="note" wire:model="note" rows="2" class="field" aria-describedby="note-error"></textarea>
                        @error('note')<p id="note-error" class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button wire:click="approve" class="btn-primary">{{ __('Approve') }}</button>
                        <button wire:click="returnUpdate" class="btn-secondary">{{ __('Return for correction') }}</button>
                        <button wire:click="reject" class="btn-danger ml-auto">{{ __('Reject') }}</button>
                    </div>
                </div>
            @else
                <div class="card px-4 py-10 text-center text-sm text-ink-3">{{ __('Select an update to review it.') }}</div>
            @endif
        </aside>
    </div>
</div>
