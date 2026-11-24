@php use App\Support\Money; use App\Livewire\Dashboard\Overview; $loc = app()->getLocale(); @endphp
<div class="space-y-4">
    <h1 class="text-2xl font-semibold text-ink">{{ __('Works') }}</h1>

    <div class="card flex flex-wrap items-end gap-3 p-3">
        <div class="min-w-56 flex-1">
            <label for="w-q" class="label text-xs">{{ __('Search code, name or e-Rupantor ID') }}</label>
            <input id="w-q" type="search" wire:model.live.debounce.300ms="search" class="field py-1.5">
        </div>
        @if ($districts->count() > 1)
            <div class="min-w-40">
                <label for="w-d" class="label text-xs">{{ __('District') }}</label>
                <select id="w-d" wire:model.live="district" class="field py-1.5">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->name($loc) }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="min-w-36">
            <label for="w-s" class="label text-xs">{{ __('Status') }}</label>
            <select id="w-s" wire:model.live="status" class="field py-1.5">
                <option value="">{{ __('All') }}</option>
                @foreach ($statusOptions as $s)<option value="{{ $s }}">{{ __(ucfirst(str_replace('_', ' ', $s))) }}</option>@endforeach
            </select>
        </div>
        <div class="min-w-44">
            <label for="w-f" class="label text-xs">{{ __('Needs attention') }}</label>
            <select id="w-f" wire:model.live="flag" class="field py-1.5">
                <option value="">{{ __('Any') }}</option>
                <option value="delayed">{{ __('Delayed') }}</option>
                <option value="stalled">{{ __('Stalled') }}</option>
                <option value="stale">{{ __('No recent update') }}</option>
                <option value="mismatch">{{ __('Spend ahead of progress') }}</option>
            </select>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line">
                <thead class="bg-surface-2">
                <tr>
                    <th class="th">{{ __('Work') }}</th>
                    <th class="th">{{ __('District') }}</th>
                    <th class="th">{{ __('Agency') }}</th>
                    <th class="th num">{{ __('Sanctioned') }}</th>
                    <th class="th num">{{ __('Physical') }}</th>
                    <th class="th num">{{ __('Financial') }}</th>
                    <th class="th num">{{ __('Last update') }}</th>
                    <th class="th">{{ __('Status') }}</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-line">
                @forelse ($works as $w)
                    <tr class="hover:bg-surface-2">
                        <td class="td">
                            <a href="{{ route('works.show', $w) }}" wire:navigate class="font-medium text-accent hover:underline">{{ Overview::workName($w) }}</a>
                            <div class="text-xs text-ink-3">{{ $w->code }}@if($w->erupantor_id) · e-R {{ $w->erupantor_id }}@endif</div>
                        </td>
                        <td class="td text-ink-2">{{ $w->district?->name($loc) }}</td>
                        <td class="td text-ink-2">{{ $w->agency?->name($loc) }}</td>
                        <td class="td num">{{ Money::short($w->sanctioned_amount) }}</td>
                        <td class="td num">{{ Money::pct($w->physical_pct) }}</td>
                        <td class="td num">{{ Money::pct((float) $w->financial_pct) }}</td>
                        <td class="td num text-ink-2"><x-last-update :at="$w->last_accepted_update_at" :by="$w->latestAcceptedUpdate?->submitter?->name" /></td>
                        <td class="td">
                            <div class="flex flex-wrap gap-1">
                                <x-status-chip :status="$w->status" />
                                @foreach (['delayed', 'stale', 'mismatch'] as $f)
                                    @if ($w->{'is_'.$f}) <x-status-chip :status="$f" /> @endif
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="td py-8 text-center text-ink-3">{{ __('No works match these filters.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($works->hasPages())
            <div class="border-t border-line px-4 py-3">{{ $works->links() }}</div>
        @endif
    </div>
</div>
