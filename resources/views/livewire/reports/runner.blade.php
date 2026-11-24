@php
    $filterLabels = [
        'fy' => __('Financial year'), 'district' => __('District'), 'co_district' => __('Co-district'),
        'constituency' => __('Constituency'), 'scheme_type' => __('Scheme type'), 'fund_source' => __('Fund source'),
        'agency' => __('Implementing agency'),
    ];
@endphp
<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-semibold text-ink">{{ __('Reports') }}</h1>
        <p class="text-sm text-ink-2">{{ __('Standard MIS reports from approved data, limited to your area. Check on screen, then download.') }}</p>
    </div>

    @if (session('status'))
        <div role="alert" class="rounded-md border border-line bg-warning-soft px-4 py-2 text-sm text-warning">{{ session('status') }}</div>
    @endif

    {{-- Report picker --}}
    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup" aria-label="{{ __('Report') }}">
        @foreach ($reports as $c => $rep)
            <button type="button" role="radio" aria-checked="{{ $code === $c ? 'true' : 'false' }}" wire:click="$set('code', '{{ $c }}')"
                @class(['card p-3 text-left transition-colors',
                        'border-accent ring-2 ring-accent/25' => $code === $c,
                        'hover:bg-surface-2' => $code !== $c])>
                <div class="text-xs font-semibold text-ink-3">{{ $c }}</div>
                <div class="font-medium text-ink">{{ $rep->title() }}</div>
                <div class="mt-0.5 text-xs text-ink-2">{{ $rep->description() }}</div>
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <form wire:submit="show" class="card flex flex-wrap items-end gap-3 p-3">
        @foreach ($filterLabels as $key => $label)
            @if (count($options[$key]) > 0)
                <div class="min-w-36">
                    <label for="f-{{ $key }}" class="label text-xs">{{ $label }}</label>
                    <select id="f-{{ $key }}" wire:model.live="filters.{{ $key }}" class="field py-1.5">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($options[$key] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            @endif
        @endforeach
        @foreach ($report->options() as $key => $choices)
            <div class="min-w-36">
                <label for="p-{{ $key }}" class="label text-xs">{{ __('Group by') }}</label>
                <select id="p-{{ $key }}" wire:model.live="params.{{ $key }}" class="field py-1.5">
                    @foreach ($choices as $val => $name)<option value="{{ $val }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        @endforeach
        <button type="submit" class="btn-primary">{{ __('Show report') }}</button>
    </form>

    @if ($result)
        <section class="card overflow-hidden" aria-labelledby="res">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 id="res" class="font-semibold text-ink">{{ $result->code }} · {{ $result->title }}</h2>
                    <div class="mt-0.5 flex flex-wrap gap-x-4 text-xs text-ink-3">
                        <span>{{ __('Data as of') }} {{ $result->asOf->format('d M Y, H:i') }}</span>
                        @foreach ($result->filterSummary as $f)<span>{{ $f }}</span>@endforeach
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="{{ $this->exportUrl('xlsx') }}" class="btn-secondary">{{ __('Excel') }}</a>
                    <a href="{{ $this->exportUrl('pdf') }}" class="btn-secondary">{{ __('PDF') }}</a>
                </div>
            </div>
            <div class="max-h-[60vh] overflow-auto">
                <table class="min-w-full divide-y divide-line">
                    <thead class="sticky top-0 bg-surface-2">
                        <tr>@foreach ($result->columns as $c)<th class="th {{ $c->isNumeric() ? 'num' : '' }}">{{ $c->label }}</th>@endforeach</tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($result->rows as $row)
                            <tr>@foreach ($result->columns as $c)<td class="td {{ $c->isNumeric() ? 'num' : '' }}">{{ $c->format($row[$c->key] ?? null) }}</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($result->columns) }}" class="td py-8 text-center text-ink-3">{{ __('No works match these filters.') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if ($result->totals)
                        <tfoot class="border-t-2 border-line-strong bg-surface-2">
                            <tr>@foreach ($result->columns as $c)<td class="td font-semibold {{ $c->isNumeric() ? 'num' : '' }}">{{ array_key_exists($c->key, $result->totals) ? $c->format($result->totals[$c->key]) : '' }}</td>@endforeach</tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>
    @endif
</div>
