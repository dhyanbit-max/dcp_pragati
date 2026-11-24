@php use App\Support\Money; use App\Livewire\Dashboard\Overview; $loc = app()->getLocale(); @endphp
<div class="mx-auto max-w-2xl space-y-4">
    <div>
        <a href="{{ route('works.show', $work) }}" wire:navigate class="text-sm text-ink-3 hover:text-accent">← {{ $work->code }}</a>
        <h1 class="mt-1 text-2xl font-semibold text-ink">{{ __('Record progress') }}</h1>
        <p class="text-sm text-ink-2">{{ Overview::workName($work) }}</p>
    </div>

    <div class="card grid grid-cols-3 gap-3 p-4 text-sm">
        <div><div class="text-xs text-ink-3">{{ __('Approved now') }}</div><div class="font-medium text-ink">{{ Money::pct($work->physical_pct) }}</div></div>
        <div><div class="text-xs text-ink-3">{{ __('Released') }}</div><div class="font-medium tabular-nums text-ink">{{ Money::inr($work->released_total) }}</div></div>
        <div><div class="text-xs text-ink-3">{{ __('Spent so far') }}</div><div class="font-medium tabular-nums text-ink">{{ Money::inr($work->spent_total) }}</div></div>
    </div>

    @if ($ruleResults)
        <div role="alert" class="rounded-md border border-line bg-critical-soft p-4 text-sm">
            <p class="font-medium text-critical">{{ __('This update was not accepted. Fix the following and submit again:') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-ink">
                @foreach ($ruleResults as $r)
                    <li><span class="font-semibold">{{ $r['code'] }}</span> · {{ $r['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="submit" class="card space-y-4 p-4" novalidate>
        <div>
            <label for="stage" class="label">{{ __('Stage') }}</label>
            <select id="stage" wire:model="stageItemId" class="field">
                @foreach ($stages as $s)
                    <option value="{{ $s->id }}">{{ $s->name($loc) }} ({{ (int) $s->min_pct }}–{{ (int) $s->max_pct }}%)</option>
                @endforeach
            </select>
            @error('stageItemId')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="pct" class="label">{{ __('Physical progress (%)') }}</label>
                <input id="pct" type="number" inputmode="decimal" step="0.5" min="0" max="100" wire:model="physicalPct" class="field tabular-nums">
                @error('physicalPct')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="spent" class="label">{{ __('Total spent to date (₹)') }}</label>
                <input id="spent" type="number" inputmode="numeric" step="1" min="0" wire:model="spentRupees" class="field tabular-nums">
                <p class="mt-1 text-xs text-ink-3">{{ __('Cumulative, not this month only.') }}</p>
                @error('spentRupees')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label for="photos" class="label">{{ __('Site photos (JPEG)') }}</label>
            <input id="photos" type="file" wire:model="photos" multiple accept="image/jpeg" class="block w-full text-sm text-ink-2 file:mr-3 file:rounded-md file:border file:border-line-strong file:bg-surface file:px-3 file:py-1.5 file:text-sm file:text-ink">
            <div wire:loading wire:target="photos" class="mt-1 text-xs text-ink-3">{{ __('Uploading…') }}</div>
            <p class="mt-1 text-xs text-ink-3">{{ __('Photos uploaded from a computer are always checked by the DPC. Use the field app to take photos at the site when you can.') }}</p>
            @if (count($storedPhotoUuids))
                <p class="mt-1 text-xs text-good">{{ trans_choice(':count photo already attached.|:count photos already attached.', count($storedPhotoUuids), ['count' => count($storedPhotoUuids)]) }}</p>
            @endif
            @error('photos')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            @error('photos.*')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="remarks" class="label">{{ __('Remarks') }}</label>
            <textarea id="remarks" rows="2" wire:model="remarks" class="field"></textarea>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('Send for approval') }}</button>
            <a href="{{ route('works.show', $work) }}" wire:navigate class="text-sm text-ink-2 hover:text-ink">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
