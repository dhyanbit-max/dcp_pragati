@php
    $report = $result ?? $preview;
    $sheetLabels = ['Districts' => __('Districts'), 'CoDistricts' => __('Co-districts'), 'Constituencies' => __('Constituencies'), 'Users' => __('Users'), 'Works' => __('Works'), 'Releases' => __('Fund releases')];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-ink">{{ __('Import data') }}</h1>
            <p class="text-sm text-ink-2">{{ __('Districts, co-districts, constituencies, users, works and fund releases from an Excel workbook, or works from a CSV. Nothing is saved until you press Import.') }}</p>
        </div>
        <a href="{{ route('import.template') }}" class="btn-secondary">{{ __('Download template') }}</a>
    </div>

    @if ($result)
        <div role="status" class="rounded-md border border-line bg-good-soft px-4 py-3 text-sm text-good">
            {{ __('Import finished.') }}
            @if (collect($result['counts'])->sum('failed'))
                {{ __('Rows with errors were skipped; fix them and import the file again. Rows already imported are matched, not duplicated.') }}
            @endif
        </div>
    @endif

    @unless ($preview)
        <form wire:submit="runPreview" class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-64 flex-1">
                <label for="file" class="label">{{ __('Workbook (.xlsx) or CSV of works') }}</label>
                <input id="file" type="file" wire:model="file" accept=".xlsx,.csv" class="block w-full text-sm text-ink-2 file:mr-3 file:rounded-md file:border file:border-line-strong file:bg-surface file:px-3 file:py-1.5 file:text-sm file:text-ink">
                <div wire:loading wire:target="file" class="mt-1 text-xs text-ink-3">{{ __('Uploading…') }}</div>
                @error('file')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled(! $file)>
                <span wire:loading.remove wire:target="runPreview">{{ __('Preview') }}</span>
                <span wire:loading wire:target="runPreview">{{ __('Checking…') }}</span>
            </button>
        </form>
    @endunless

    @if ($report)
        <section class="card overflow-hidden" aria-labelledby="rep">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <h2 id="rep" class="font-semibold text-ink">
                    {{ $preview ? __('Preview of :file', ['file' => $fileName]) : __('Result for :file', ['file' => $fileName]) }}
                </h2>
                @if ($preview)
                    <div class="flex gap-2">
                        <button wire:click="startOver" class="btn-secondary">{{ __('Choose another file') }}</button>
                        <button wire:click="import" wire:loading.attr="disabled" class="btn-primary"
                                @disabled(collect($preview['counts'])->sum(fn ($c) => $c['created'] + $c['updated']) === 0)>
                            {{ __('Import') }}
                        </button>
                    </div>
                @else
                    <button wire:click="startOver" class="btn-secondary">{{ __('Import another file') }}</button>
                @endif
            </div>

            <table class="min-w-full divide-y divide-line">
                <thead class="bg-surface-2">
                    <tr>
                        <th class="th">{{ __('Sheet') }}</th>
                        <th class="th num">{{ $preview ? __('Will create') : __('Created') }}</th>
                        <th class="th num">{{ $preview ? __('Will update') : __('Updated') }}</th>
                        <th class="th num">{{ __('Unchanged') }}</th>
                        <th class="th num">{{ __('Errors') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($report['counts'] as $sheet => $c)
                        <tr>
                            <td class="td font-medium">{{ $sheetLabels[$sheet] ?? $sheet }}</td>
                            <td class="td num">{{ $c['created'] }}</td>
                            <td class="td num">{{ $c['updated'] }}</td>
                            <td class="td num text-ink-3">{{ $c['skipped'] }}</td>
                            <td @class(['td num', 'font-semibold text-critical' => $c['failed'] > 0])>{{ $c['failed'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="td py-6 text-center text-ink-3">{{ __('No rows found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if (count($report['issues']))
                <div class="border-t border-line">
                    <h3 class="px-4 pt-3 text-sm font-semibold text-ink">{{ __('Rows to check') }}</h3>
                    <div class="max-h-96 overflow-y-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr><th class="th">{{ __('Sheet') }}</th><th class="th num">{{ __('Row') }}</th><th class="th">{{ __('Problem') }}</th></tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($report['issues'] as $i)
                                    <tr>
                                        <td class="td">{{ $sheetLabels[$i['sheet']] ?? $i['sheet'] }}</td>
                                        <td class="td num">{{ $i['row'] ?: '—' }}</td>
                                        <td class="td">
                                            <span class="chip {{ $i['level'] === 'error' ? 'bg-critical-soft text-critical' : 'bg-warning-soft text-warning' }}">{{ $i['level'] === 'error' ? __('Skipped') : __('Note') }}</span>
                                            {{ $i['message'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endif

    <section class="card overflow-hidden" aria-labelledby="hist">
        <h2 id="hist" class="border-b border-line px-4 py-3 font-semibold text-ink">{{ __('Recent imports') }}</h2>
        <table class="min-w-full divide-y divide-line text-sm">
            <tbody class="divide-y divide-line">
                @forelse ($history as $b)
                    @php $counts = collect($b->counts_json['counts'] ?? []); @endphp
                    <tr>
                        <td class="td tabular-nums text-ink-2">{{ $b->started_at->format('d M Y H:i') }}</td>
                        <td class="td">{{ $b->counts_json['file'] ?? '—' }}</td>
                        <td class="td text-ink-2">{{ $b->starter?->name }}</td>
                        <td class="td num">{{ __(':c created, :u updated', ['c' => $counts->sum('created'), 'u' => $counts->sum('updated')]) }}</td>
                        <td class="td">
                            @if ($counts->sum('failed'))
                                <span class="chip bg-warning-soft text-warning">{{ __(':n skipped', ['n' => $counts->sum('failed')]) }}</span>
                            @else
                                <span class="chip bg-good-soft text-good">{{ __('Complete') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="td py-6 text-center text-ink-3">{{ __('No imports yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
