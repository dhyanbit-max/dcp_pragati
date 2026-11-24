@php use App\Domain\Grievances\GrievanceService; use App\Models\Grievance; $loc = app()->getLocale(); @endphp
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <h1 class="text-2xl font-semibold text-ink sm:text-3xl">{{ __('Track a grievance') }}</h1>

    @if (! $g)
        <form wire:submit="find" class="card mt-6 max-w-md space-y-4 p-6">
            <p class="text-sm text-ink-2">{{ __('Enter the reference number from your SMS and the mobile number you used.') }}</p>
            <div>
                <label for="t-ref" class="label">{{ __('Reference number') }}</label>
                <input id="t-ref" wire:model="ref" class="field uppercase tracking-wide" placeholder="GRV-26-000123" autocomplete="off">
                @error('ref')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="t-mobile" class="label">{{ __('Mobile number') }}</label>
                <input id="t-mobile" wire:model="mobile" inputmode="numeric" maxlength="14" class="field" autocomplete="tel-national">
                @error('mobile')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-2.5">{{ __('Show status') }}</button>
            @if (config('dcp.public.grievances'))
                <p class="text-center text-sm text-ink-3">{{ __('Have not raised one yet?') }} <a href="{{ route('grievances.create') }}" class="text-accent hover:underline">{{ __('Raise a grievance') }}</a></p>
            @endif
        </form>
    @else
        @php
            $flow = [Grievance::SUBMITTED, Grievance::ACKNOWLEDGED, Grievance::IN_PROGRESS, $g->status === Grievance::REJECTED ? Grievance::REJECTED : Grievance::RESOLVED];
            $at = array_search($g->status, $flow, true);
        @endphp
        <div class="card mt-6 overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line p-5">
                <div>
                    <div class="font-mono text-sm text-ink-3">{{ $g->ref_no }}</div>
                    <h2 class="mt-0.5 text-lg font-semibold text-ink">{{ $g->subject }}</h2>
                    <div class="mt-1 text-sm text-ink-2">{{ GrievanceService::categoryLabel($g->category) }} · {{ $g->coDistrict?->name($loc) ? $g->coDistrict->name($loc).', ' : '' }}{{ $g->district->name($loc) }}</div>
                </div>
                <span @class(['rounded-full px-3 py-1 text-sm font-semibold',
                    'bg-good-soft text-good' => $g->status === Grievance::RESOLVED,
                    'bg-neutral-soft text-ink-2' => $g->status === Grievance::REJECTED,
                    'bg-accent-soft text-accent' => $g->isOpen()])>{{ GrievanceService::statusLabel($g->status) }}</span>
            </div>

            {{-- Progress --}}
            <ol class="grid grid-cols-4 gap-1 px-5 pt-5 text-center text-xs" aria-label="{{ __('Progress') }}">
                @foreach ($flow as $i => $s)
                    <li>
                        <div @class(['h-1.5 rounded-full', 'bg-accent' => $i <= $at, 'bg-surface-2' => $i > $at])></div>
                        <div @class(['mt-1.5', 'font-medium text-ink' => $i === $at, 'text-ink-3' => $i !== $at])>{{ GrievanceService::statusLabel($s) }}</div>
                    </li>
                @endforeach
            </ol>

            <div class="grid gap-4 p-5 text-sm sm:grid-cols-3">
                <div><div class="text-xs text-ink-3">{{ __('Received') }}</div><div class="font-medium text-ink">{{ $g->created_at->format('j M Y, g:i A') }}</div></div>
                @if ($g->isOpen())
                    <div><div class="text-xs text-ink-3">{{ __('To be acknowledged by') }}</div><div class="font-medium text-ink">{{ $g->acknowledged_at ? __('Done') : $g->acknowledge_by->format('j M Y') }}</div></div>
                    <div><div class="text-xs text-ink-3">{{ __('To be answered by') }}</div><div class="font-medium text-ink">{{ $g->resolve_by->format('j M Y') }}</div></div>
                @else
                    <div><div class="text-xs text-ink-3">{{ __('Closed') }}</div><div class="font-medium text-ink">{{ $g->closed_at?->format('j M Y, g:i A') }}</div></div>
                @endif
            </div>

            @if ($g->resolution_note)
                <div class="mx-5 mb-5 rounded-lg border border-line bg-surface-2 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-ink-3">{{ $g->status === Grievance::RESOLVED ? __('What was done') : __('Reason') }}</div>
                    <p class="mt-1 whitespace-pre-line text-ink">{{ $g->resolution_note }}</p>
                </div>
            @endif

            <div class="border-t border-line p-5">
                <h3 class="text-sm font-semibold text-ink">{{ __('Updates') }}</h3>
                <ol class="mt-3 space-y-3 border-l-2 border-line pl-4">
                    @foreach ($g->events as $e)
                        <li class="relative">
                            <span class="absolute -left-[1.4rem] top-1 size-2.5 rounded-full bg-accent ring-4 ring-surface" aria-hidden="true"></span>
                            <div class="text-xs text-ink-3">{{ $e->created_at->format('j M Y, g:i A') }}</div>
                            <div class="text-sm text-ink">
                                @if ($e->type === 'submitted') {{ __('Grievance received by the district office.') }}
                                @elseif ($e->type === 'status') {{ __('Status: :s', ['s' => GrievanceService::statusLabel($e->to_status)]) }}
                                @else {{ __('Note from the district office') }}
                                @endif
                            </div>
                            @if ($e->note && $e->type !== 'submitted')<p class="mt-0.5 whitespace-pre-line text-sm text-ink-2">{{ $e->note }}</p>@endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
        <button type="button" wire:click="again" class="mt-4 text-sm text-accent hover:underline">← {{ __('Look up another') }}</button>
    @endif
</div>
