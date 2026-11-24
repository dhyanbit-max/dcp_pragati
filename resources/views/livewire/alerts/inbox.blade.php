@php
    use App\Livewire\Dashboard\Overview;
    $loc = app()->getLocale();
    $levels = [0 => __('Owner'), 1 => __('DPC'), 2 => __('Deputy Commissioner'), 3 => __('DCP Division')];
@endphp
<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-semibold text-ink">{{ __('Alerts') }}</h1>
        <p class="text-sm text-ink-2">{{ __('Alerts open automatically and close when the problem is fixed. Unhandled alerts move up to the DPC after 3 days, the Deputy Commissioner after 10 and the DCP Division after 24.') }}</p>
    </div>

    @if ($flash)
        <div role="status" class="rounded-md border border-line bg-good-soft px-4 py-2 text-sm text-good">{{ $flash }}</div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-line">
        <div role="tablist" class="flex gap-4 text-sm">
            @foreach (['open' => __('Open'), 'acknowledged' => __('Acknowledged'), 'closed' => __('Closed (30 days)')] as $key => $label)
                <button role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                    @class(['-mb-px inline-flex items-center gap-1.5 border-b-2 pb-2 font-medium',
                            'border-accent text-accent' => $tab === $key,
                            'border-transparent text-ink-2 hover:text-ink' => $tab !== $key])>
                    {{ $label }}
                    @isset($counts[$key])<span class="rounded-full bg-surface-2 px-1.5 text-xs tabular-nums text-ink-2">{{ $counts[$key] }}</span>@endisset
                </button>
            @endforeach
        </div>
        <div class="pb-2">
            <label for="a-code" class="sr-only">{{ __('Alert type') }}</label>
            <select id="a-code" wire:model.live="code" class="field py-1.5">
                <option value="">{{ __('All alert types') }}</option>
                @foreach (\App\Domain\Alerts\AlertCode::ALL as $c)
                    <option value="{{ $c }}">{{ $c }} · {{ __("alert_types.$c.title") }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <ul class="card divide-y divide-line">
        @forelse ($alerts as $a)
            <li class="px-4 py-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="chip bg-warning-soft text-warning">{{ $a->alert_code }}</span>
                            <span class="font-medium text-ink">{{ __("alert_types.{$a->alert_code}.title") }}</span>
                            @if ($a->level > 0)
                                <span class="chip bg-serious-soft text-serious">↑ {{ $levels[$a->level] ?? $a->level }}</span>
                            @endif
                        </div>
                        <a href="{{ route('works.show', $a->work_id) }}" wire:navigate class="mt-1 block text-sm text-accent hover:underline">
                            {{ $a->work->code }} · {{ Overview::workName($a->work) }}
                        </a>
                        <div class="text-xs text-ink-3">
                            {{ $a->work->district?->name($loc) }} · {{ __('opened :when', ['when' => $a->opened_at->diffForHumans()]) }}
                            @if ($a->acknowledged_at && $a->status === 'acknowledged') · {{ __('acknowledged :when', ['when' => $a->acknowledged_at->diffForHumans()]) }} @endif
                            @if ($a->resolved_at) · {{ $a->status === 'auto_closed' ? __('closed automatically') : __('resolved') }} {{ $a->resolved_at->diffForHumans() }} @endif
                        </div>
                        @if ($a->resolution_note)
                            <p class="mt-1 text-sm text-ink-2">“{{ $a->resolution_note }}”</p>
                        @endif
                    </div>
                    @if (in_array($a->status, ['open', 'acknowledged']) && $acting !== $a->id)
                        <div class="flex gap-2">
                            @if ($a->status === 'open')
                                <button wire:click="start({{ $a->id }}, 'acknowledge')" class="btn-secondary py-1.5">{{ __('Acknowledge') }}</button>
                            @endif
                            <button wire:click="start({{ $a->id }}, 'resolve')" class="btn-secondary py-1.5">{{ __('Resolve') }}</button>
                        </div>
                    @endif
                </div>

                @if ($acting === $a->id)
                    <form wire:submit="confirm" class="mt-3 flex flex-wrap items-end gap-2" novalidate>
                        <div class="min-w-64 flex-1">
                            <label for="note-{{ $a->id }}" class="label">
                                {{ $action === 'resolve' ? __('How was it resolved?') : __('What is being done?') }}
                            </label>
                            <input id="note-{{ $a->id }}" type="text" wire:model="note" class="field" autofocus>
                            @error('note')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn-primary">{{ $action === 'resolve' ? __('Resolve') : __('Acknowledge') }}</button>
                        <button type="button" wire:click="cancel" class="btn-secondary">{{ __('Cancel') }}</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-ink-3">{{ __('No alerts here.') }}</li>
        @endforelse
    </ul>
    @if ($alerts->hasPages())
        <div>{{ $alerts->links() }}</div>
    @endif
</div>
