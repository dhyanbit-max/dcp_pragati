@php use App\Support\Money; use App\Livewire\Dashboard\Overview; use App\Livewire\Progress\BulkEditor; $loc = app()->getLocale(); @endphp
<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-semibold text-ink">{{ __('Bulk edit') }}</h1>
        <p class="text-sm text-ink-2">{{ __('Change stage, physical % or total spent for several works, then save. Each changed row is checked by the rules and sent to the DPC as its own update.') }}</p>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md border border-line bg-surface-2 p-3 text-sm text-ink">{{ session('status') }}</div>
    @endif

    <div class="card flex flex-wrap items-end gap-3 p-3">
        <div class="min-w-56 flex-1">
            <label for="b-q" class="label text-xs">{{ __('Search code, name or e-Rupantor ID') }}</label>
            <input id="b-q" type="search" wire:model.live.debounce.300ms="search" class="field py-1.5">
        </div>
        @if ($districts->count() > 1)
            <div class="min-w-40">
                <label for="b-d" class="label text-xs">{{ __('District') }}</label>
                <select id="b-d" wire:model.live="district" class="field py-1.5">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->name($loc) }}</option>@endforeach
                </select>
            </div>
        @endif
    </div>

    <form wire:submit="save" class="space-y-3" novalidate>
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line">
                    <thead class="bg-surface-2">
                    <tr>
                        <th class="th">{{ __('Work') }}</th>
                        <th class="th">{{ __('Approved now') }}</th>
                        <th class="th">{{ __('Stage') }}</th>
                        <th class="th num">{{ __('Physical %') }}</th>
                        <th class="th num">{{ __('Total spent (₹)') }}</th>
                        <th class="th">{{ __('Result') }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                    @forelse ($works as $w)
                        @php $changed = in_array($w->id, $changedIds, true); $res = $results[$w->id] ?? null; @endphp
                        <tr wire:key="row-{{ $w->id }}" @class(['bg-accent-soft' => $changed])>
                            <td class="td">
                                <a href="{{ route('works.show', $w) }}" wire:navigate class="font-medium text-accent hover:underline">{{ Overview::workName($w) }}</a>
                                <div class="text-xs text-ink-3">{{ $w->code }} · {{ $w->district?->name($loc) }}</div>
                                @if ($w->pending_count > 0)
                                    <div class="text-xs text-ink-2">{{ trans_choice(':count update waiting for approval|:count updates waiting for approval', $w->pending_count, ['count' => $w->pending_count]) }}</div>
                                @endif
                            </td>
                            <td class="td text-sm text-ink-2 whitespace-nowrap">
                                {{ $w->currentStage?->name($loc) ?? '–' }}<br>
                                {{ Money::pct($w->physical_pct) }} · {{ Money::inr($w->spent_total) }}
                            </td>
                            <td class="td">
                                <label for="st-{{ $w->id }}" class="sr-only">{{ __('Stage') }}</label>
                                <select id="st-{{ $w->id }}" wire:model.live="rows.{{ $w->id }}.stage" class="field py-1 text-sm">
                                    @foreach ($stagesByTemplate[$w->stage_template_id] ?? [] as $s)
                                        <option value="{{ $s->id }}">{{ $s->name($loc) }} ({{ (int) $s->min_pct }}–{{ (int) $s->max_pct }}%)</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="td num">
                                <label for="pc-{{ $w->id }}" class="sr-only">{{ __('Physical %') }}</label>
                                <input id="pc-{{ $w->id }}" type="number" inputmode="decimal" step="0.5" min="0" max="100" wire:model.live.debounce.400ms="rows.{{ $w->id }}.pct" class="field w-24 py-1 text-right tabular-nums">
                            </td>
                            <td class="td num">
                                <label for="sp-{{ $w->id }}" class="sr-only">{{ __('Total spent (₹)') }}</label>
                                <input id="sp-{{ $w->id }}" type="number" inputmode="numeric" step="1" min="0" wire:model.live.debounce.400ms="rows.{{ $w->id }}.spent" class="field w-36 py-1 text-right tabular-nums">
                            </td>
                            <td class="td text-sm">
                                @if ($res)
                                    @if (in_array($res['status'], ['rejected', 'invalid'], true))
                                        <p class="font-medium text-critical">{{ __('Not accepted') }}</p>
                                    @elseif ($res['status'] === 'accepted')
                                        <p class="font-medium text-good">{{ __('Accepted') }}</p>
                                    @else
                                        <p class="font-medium text-ink">{{ $res['flagged'] ? __('Sent, flagged for review') : __('Sent for approval') }}</p>
                                    @endif
                                    @if ($res['messages'])
                                        <ul class="mt-1 space-y-0.5 text-xs text-ink-2">
                                            @foreach ($res['messages'] as $m)<li>{{ $m }}</li>@endforeach
                                        </ul>
                                    @endif
                                @endif
                                @if ($changed)
                                    <button type="button" wire:click="resetRow({{ $w->id }})" class="text-xs text-ink-2 underline hover:text-ink">{{ __('Undo') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="td py-8 text-center text-ink-3">{{ __('No works match these filters.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($works->hasPages())
                <div class="border-t border-line px-4 py-3">{{ $works->links() }}</div>
            @endif
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-3">
            <div class="min-w-64 flex-1">
                <label for="b-r" class="label text-xs">{{ __('Remarks (added to every saved row)') }}</label>
                <input id="b-r" type="text" wire:model="remarks" maxlength="1000" class="field py-1.5">
                @error('remarks')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-ink-2 tabular-nums">{{ trans_choice(':count row changed|:count rows changed', count($changedIds), ['count' => count($changedIds)]) }}</span>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save" @disabled(count($changedIds) === 0)>{{ __('Save changes') }}</button>
            </div>
            @error('rows')<p class="w-full text-sm text-critical">{{ $message }}</p>@enderror
            <p class="w-full text-xs text-ink-3">{{ __('Rows on other pages keep their changes until you save. Changing the stage or physical % may need site photos; if a row is not accepted for that reason, use Record progress on the work page instead.') }}</p>
        </div>
    </form>
</div>
