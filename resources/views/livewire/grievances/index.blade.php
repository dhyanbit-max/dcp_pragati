@php use App\Domain\Grievances\GrievanceService; use App\Models\Grievance; $loc = app()->getLocale(); @endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-2xl font-semibold text-ink">{{ __('Grievances') }}</h1>
            <p class="text-sm text-ink-2">{{ __('From citizens, about works in your area. Acknowledge within :a days, answer within :r days.', ['a' => config('dcp.grievances.acknowledge_days'), 'r' => config('dcp.grievances.resolve_days')]) }}</p>
        </div>
        <a href="{{ route('grievances.create') }}" target="_blank" rel="noopener" class="text-sm text-accent hover:underline">{{ __('Public form') }} ↗</a>
    </div>

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="{{ __('Key figures') }}">
        <button type="button" wire:click="$set('status', 'open')" class="text-left"><x-kpi :label="__('Open')" :value="$counts['open']" /></button>
        <button type="button" wire:click="$set('status', 'overdue')" class="text-left"><x-kpi :label="__('Past deadline')" :value="$counts['overdue']" :tone="$counts['overdue'] > 0 ? 'critical' : 'good'" /></button>
        <button type="button" wire:click="$set('status', 'resolved')" class="text-left"><x-kpi :label="__('Resolved, last 30 days')" :value="$counts['resolved30']" /></button>
        <x-kpi :label="__('Average days to close')" :value="$counts['avgDays'] ?? '—'" :hint="__('Last 90 days · target :d', ['d' => config('dcp.grievances.resolve_days')])" />
    </section>

    <div class="card flex flex-wrap items-end gap-3 p-3">
        <div class="min-w-56 flex-1">
            <label for="gr-q" class="label text-xs">{{ __('Search reference, subject or work') }}</label>
            <input id="gr-q" type="search" wire:model.live.debounce.300ms="search" class="field py-1.5">
        </div>
        <div class="min-w-40">
            <label for="gr-s" class="label text-xs">{{ __('Status') }}</label>
            <select id="gr-s" wire:model.live="status" class="field py-1.5">
                <option value="open">{{ __('Open') }}</option>
                <option value="overdue">{{ __('Past deadline') }}</option>
                @foreach (array_keys(Grievance::TRANSITIONS) as $s)<option value="{{ $s }}">{{ GrievanceService::statusLabel($s) }}</option>@endforeach
                <option value="all">{{ __('All') }}</option>
            </select>
        </div>
        <div class="min-w-48">
            <label for="gr-c" class="label text-xs">{{ __('Type') }}</label>
            <select id="gr-c" wire:model.live="category" class="field py-1.5">
                <option value="">{{ __('All') }}</option>
                @foreach (config('dcp.grievances.categories') as $c)<option value="{{ $c }}">{{ GrievanceService::categoryLabel($c) }}</option>@endforeach
            </select>
        </div>
        @if ($districts->count() > 1)
            <div class="min-w-40">
                <label for="gr-d" class="label text-xs">{{ __('District') }}</label>
                <select id="gr-d" wire:model.live="district" class="field py-1.5">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->name($loc) }}</option>@endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line">
                <thead class="bg-surface-2">
                <tr>
                    <th class="th">{{ __('Grievance') }}</th>
                    <th class="th">{{ __('Where') }}</th>
                    <th class="th">{{ __('Received') }}</th>
                    <th class="th">{{ __('Due') }}</th>
                    <th class="th">{{ __('Handled by') }}</th>
                    <th class="th">{{ __('Status') }}</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-line">
                @forelse ($grievances as $g)
                    @php $late = $g->isOverdue(); @endphp
                    <tr class="hover:bg-surface-2">
                        <td class="td">
                            <a href="{{ route('grievances.show', $g) }}" wire:navigate class="font-medium text-accent hover:underline">{{ $g->subject }}</a>
                            <div class="text-xs text-ink-3"><span class="font-mono">{{ $g->ref_no }}</span> · {{ GrievanceService::categoryLabel($g->category) }}@if($g->photo_path) · 📷@endif</div>
                        </td>
                        <td class="td text-sm text-ink-2">{{ $g->coDistrict?->name($loc) ?? '—' }}<div class="text-xs text-ink-3">{{ $g->district->name($loc) }}</div></td>
                        <td class="td text-sm text-ink-2">{{ $g->created_at->format('j M, g:i A') }}<div class="text-xs text-ink-3">{{ $g->created_at->diffForHumans() }}</div></td>
                        <td @class(['td text-sm', 'font-semibold text-critical' => $late, 'text-ink-2' => ! $late])>
                            @if ($g->isOpen())
                                {{ ($g->status === Grievance::SUBMITTED ? $g->acknowledge_by : $g->resolve_by)->format('j M') }}
                                <div class="text-xs font-normal {{ $late ? 'text-critical' : 'text-ink-3' }}">{{ $g->status === Grievance::SUBMITTED ? __('acknowledge') : __('answer') }}@if($late) · {{ __('late') }}@endif</div>
                            @else
                                <span class="text-ink-3">—</span>
                            @endif
                        </td>
                        <td class="td text-sm text-ink-2">{{ $g->assignee?->name ?? __('Not assigned') }}</td>
                        <td class="td"><x-grievance-status :status="$g->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="td py-10 text-center text-ink-3">{{ __('No grievances here.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($grievances->hasPages())
            <div class="border-t border-line px-4 py-3">{{ $grievances->links() }}</div>
        @endif
    </div>
</div>
