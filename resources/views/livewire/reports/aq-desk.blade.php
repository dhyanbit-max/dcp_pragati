<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-semibold text-ink">{{ __('Assembly Question desk') }}</h1>
        <p class="text-sm text-ink-2">{{ __('Prepare a reply with current approved figures by constituency, scheme and year, then download it as PDF or Excel.') }}</p>
    </div>

    @if (session('status'))
        <div role="alert" class="rounded-md border border-line bg-warning-soft px-4 py-2 text-sm text-warning">{{ session('status') }}</div>
    @endif

    @if (! $result)
        <form wire:submit="prepare" class="card space-y-4 p-4" novalidate>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="q-no" class="label">{{ __('Question number') }}</label>
                    <input id="q-no" type="text" wire:model="questionNo" class="field">
                    @error('questionNo')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="q-session" class="label">{{ __('Session') }}</label>
                    <input id="q-session" type="text" wire:model="session" placeholder="{{ __('e.g. Budget Session 2027') }}" class="field">
                </div>
                <div>
                    <label for="q-member" class="label">{{ __('Member') }}</label>
                    <input id="q-member" type="text" wire:model="memberName" class="field">
                </div>
            </div>
            <div>
                <label for="q-text" class="label">{{ __('Question (optional)') }}</label>
                <textarea id="q-text" rows="2" wire:model="questionText" class="field"></textarea>
            </div>

            <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <legend class="mb-2 text-sm font-semibold text-ink">{{ __('What the question covers') }}</legend>
                <div class="sm:col-span-2 lg:col-span-1">
                    <label for="q-ac" class="label">{{ __('Constituencies') }}</label>
                    <select id="q-ac" wire:model="constituencies" multiple size="5" class="field">
                        @foreach ($options['constituency'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <p class="mt-1 text-xs text-ink-3">{{ __('None selected means all in your area. Ctrl-click to pick several.') }}</p>
                </div>
                <div>
                    <label for="q-scheme" class="label">{{ __('Scheme type') }}</label>
                    <select id="q-scheme" wire:model="schemeType" class="field"><option value="">{{ __('All') }}</option>
                        @foreach ($options['scheme_type'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="q-fund" class="label">{{ __('Fund source') }}</label>
                    <select id="q-fund" wire:model="fundSource" class="field"><option value="">{{ __('All') }}</option>
                        @foreach ($options['fund_source'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="q-fy" class="label">{{ __('Financial year') }}</label>
                    <select id="q-fy" wire:model="fy" class="field"><option value="">{{ __('All') }}</option>
                        @foreach ($options['fy'] as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach
                    </select>
                </div>
            </fieldset>

            <div>
                <label for="q-note" class="label">{{ __('Cover note (optional)') }}</label>
                <textarea id="q-note" rows="2" wire:model="note" class="field"></textarea>
            </div>
            <button type="submit" class="btn-primary">{{ __('Prepare reply') }}</button>
        </form>
    @else
        <section class="card overflow-hidden" aria-labelledby="aq-res">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 id="aq-res" class="font-semibold text-ink">{{ $result->heading }}</h2>
                    <div class="mt-0.5 flex flex-wrap gap-x-4 text-xs text-ink-3">
                        <span>{{ __('Data as of') }} {{ $result->asOf->format('d M Y, H:i') }}</span>
                        @foreach ($result->filterSummary as $f)<span>{{ $f }}</span>@endforeach
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('aq.export', [$answer, 'xlsx']) }}" class="btn-secondary">{{ __('Excel') }}</a>
                    <a href="{{ route('aq.export', [$answer, 'pdf']) }}" class="btn-primary">{{ __('PDF') }}</a>
                    <button wire:click="startNew" class="btn-secondary">{{ __('New question') }}</button>
                </div>
            </div>
            @if ($result->note)<p class="whitespace-pre-line border-b border-line px-4 py-3 text-sm text-ink-2">{{ $result->note }}</p>@endif
            <dl class="grid grid-cols-2 gap-3 border-b border-line px-4 py-3 sm:grid-cols-4 lg:grid-cols-7">
                @foreach ($result->summary as $label => $value)
                    <div><dt class="text-xs text-ink-3">{{ $label }}</dt><dd class="font-semibold tabular-nums text-ink">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <div class="max-h-[55vh] overflow-auto">
                <table class="min-w-full divide-y divide-line">
                    <thead class="sticky top-0 bg-surface-2">
                        <tr>@foreach ($result->columns as $c)<th class="th {{ $c->isNumeric() ? 'num' : '' }}">{{ $c->label }}</th>@endforeach</tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($result->rows as $row)
                            <tr>@foreach ($result->columns as $c)<td class="td {{ $c->isNumeric() ? 'num' : '' }}">{{ $c->format($row[$c->key] ?? null) }}</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($result->columns) }}" class="td py-8 text-center text-ink-3">{{ __('No works match.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card overflow-hidden" aria-labelledby="aq-hist">
        <h2 id="aq-hist" class="border-b border-line px-4 py-3 font-semibold text-ink">{{ __('Recent replies') }}</h2>
        <table class="min-w-full divide-y divide-line text-sm">
            <tbody class="divide-y divide-line">
                @forelse ($history as $h)
                    <tr>
                        <td class="td font-medium">{{ __('AQ :no', ['no' => $h->question_no]) }}</td>
                        <td class="td text-ink-2">{{ $h->session }}</td>
                        <td class="td text-ink-2">{{ $h->answerer?->name }} · {{ $h->answered_at?->format('d M Y H:i') }}</td>
                        <td class="td num">{{ trans_choice(':count work|:count works', $h->params_json['works'] ?? 0, ['count' => $h->params_json['works'] ?? 0]) }}</td>
                        <td class="td text-right">
                            <a href="{{ route('aq.export', [$h, 'pdf']) }}" class="text-accent hover:underline">PDF</a> ·
                            <a href="{{ route('aq.export', [$h, 'xlsx']) }}" class="text-accent hover:underline">Excel</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="td py-6 text-center text-ink-3">{{ __('No replies yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
