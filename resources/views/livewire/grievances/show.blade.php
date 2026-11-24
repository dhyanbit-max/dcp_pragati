@php use App\Domain\Grievances\GrievanceService; use App\Models\Grievance; $loc = app()->getLocale(); $next = Grievance::TRANSITIONS[$g->status]; @endphp
<div class="space-y-4">
    <nav class="text-sm text-ink-3" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('grievances.index') }}" wire:navigate class="hover:text-accent hover:underline">{{ __('Grievances') }}</a>
        <span aria-hidden="true">/</span> <span class="font-mono">{{ $g->ref_no }}</span>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-ink">{{ $g->subject }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink-2">
                <x-grievance-status :status="$g->status" />
                <span>{{ GrievanceService::categoryLabel($g->category) }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $g->coDistrict ? $g->coDistrict->name($loc).', ' : '' }}{{ $g->district->name($loc) }}</span>
                @if ($g->isOverdue())<span class="rounded-full bg-critical-soft px-2 py-0.5 text-xs font-semibold text-critical">{{ __('Past deadline') }}</span>@endif
            </div>
        </div>
    </div>

    @if ($flash)<div class="rounded-lg border border-line bg-good-soft px-4 py-2 text-sm text-good" role="status">{{ $flash }}</div>@endif
    @error('action')<div class="rounded-lg border border-line bg-critical-soft px-4 py-2 text-sm text-critical" role="alert">{{ $message }}</div>@enderror

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-4">
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('What the citizen says') }}</h2>
                <p class="mt-2 whitespace-pre-line text-ink">{{ $g->description }}</p>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-ink-3">{{ __('Work, as they named it') }}</dt><dd class="text-ink">{{ $g->work_reference ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Place') }}</dt><dd class="text-ink">{{ $g->location_text ?: '—' }}
                        @if ($g->lat)<span class="block text-xs tabular-nums text-ink-3">{{ number_format($g->lat, 5) }}, {{ number_format($g->lng, 5) }}</span>@endif</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Received') }}</dt><dd class="text-ink">{{ $g->created_at->format('j M Y, g:i A') }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Deadlines') }}</dt><dd class="text-ink">{{ __('Acknowledge by :a, answer by :r', ['a' => $g->acknowledge_by->format('j M'), 'r' => $g->resolve_by->format('j M')]) }}</dd></div>
                </dl>
                @if ($g->photo_path)
                    <a href="{{ route('grievances.photo', $g) }}" target="_blank" rel="noopener" class="mt-4 block w-fit">
                        <img src="{{ route('grievances.photo', $g) }}" alt="{{ __('Photo from the citizen') }}" class="max-h-72 rounded-lg border border-line object-cover">
                    </a>
                @endif
            </section>

            {{-- Actions --}}
            @if ($g->isOpen())
                <section class="card space-y-4 p-5" aria-labelledby="act-h">
                    <h2 id="act-h" class="text-sm font-semibold text-ink">{{ __('Take action') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        @if (in_array(Grievance::ACKNOWLEDGED, $next, true))
                            <button type="button" wire:click="move('acknowledged')" class="btn-secondary">{{ __('Acknowledge') }}</button>
                        @endif
                        @if (in_array(Grievance::IN_PROGRESS, $next, true))
                            <button type="button" wire:click="move('in_progress')" class="btn-secondary">{{ __('Mark as being looked into') }}</button>
                        @endif
                        <button type="button" wire:click="$set('closeAs', 'resolved')" class="btn-primary">{{ __('Resolve') }}</button>
                        <button type="button" wire:click="$set('closeAs', 'rejected')" class="btn-secondary">{{ __('Close without action') }}</button>
                    </div>
                    @if ($closeAs)
                        <form wire:submit="close" class="space-y-2 rounded-lg border border-line bg-surface-2 p-4">
                            <label for="close-note" class="label">{{ $closeAs === 'resolved' ? __('What was done') : __('Why no action is needed') }}</label>
                            <textarea id="close-note" wire:model="closeNote" rows="3" maxlength="2000" class="field" placeholder="{{ $closeAs === 'resolved' ? __('e.g. Site visited on 25 Nov; contractor relaid the damaged stretch.') : __('e.g. The work is under a different department; forwarded to PWD.') }}"></textarea>
                            @error('closeNote')<p class="text-sm text-critical">{{ $message }}</p>@enderror
                            <p class="text-xs text-ink-3">{{ __('The citizen sees this note and gets it by SMS.') }}</p>
                            <div class="flex gap-2">
                                <button type="submit" class="btn-primary">{{ $closeAs === 'resolved' ? __('Resolve and notify') : __('Close and notify') }}</button>
                                <button type="button" wire:click="$set('closeAs', null)" class="btn-secondary">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @endif
                </section>
            @elseif ($g->resolution_note)
                <section class="card p-5">
                    <h2 class="text-sm font-semibold text-ink">{{ $g->status === Grievance::RESOLVED ? __('What was done') : __('Reason') }}</h2>
                    <p class="mt-1 whitespace-pre-line text-ink">{{ $g->resolution_note }}</p>
                    <p class="mt-2 text-xs text-ink-3">{{ __('Closed :t', ['t' => $g->closed_at?->format('j M Y, g:i A')]) }}</p>
                </section>
            @endif

            {{-- Timeline --}}
            <section class="card p-5" aria-labelledby="tl-h">
                <h2 id="tl-h" class="text-sm font-semibold text-ink">{{ __('Timeline') }}</h2>
                <ol class="mt-3 space-y-4 border-l-2 border-line pl-4">
                    @foreach ($g->events as $e)
                        <li class="relative">
                            <span @class(['absolute -left-[1.4rem] top-1 size-2.5 rounded-full ring-4 ring-surface', 'bg-accent' => $e->is_public, 'bg-ink-3' => ! $e->is_public]) aria-hidden="true"></span>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-ink-3">
                                <span>{{ $e->created_at->format('j M Y, g:i A') }}</span>
                                <span>· {{ $e->user?->name ?? __('Citizen') }}</span>
                                <span @class(['rounded px-1.5', 'bg-accent-soft text-accent' => $e->is_public, 'bg-surface-2 text-ink-3' => ! $e->is_public])>{{ $e->is_public ? __('Citizen can see') : __('Internal') }}</span>
                            </div>
                            <div class="text-sm text-ink">
                                @switch($e->type)
                                    @case('submitted') {{ __('Grievance submitted (mobile verified by OTP).') }} @break
                                    @case('status') {{ __('Status: :a → :b', ['a' => GrievanceService::statusLabel($e->from_status), 'b' => GrievanceService::statusLabel($e->to_status)]) }} @break
                                    @case('assigned') {{ __('Assigned to :n', ['n' => $e->note]) }} @break
                                    @case('work_linked') {{ $e->note ? __('Linked to work :c', ['c' => $e->note]) : __('Work link removed') }} @break
                                    @default {{ __('Note') }}
                                @endswitch
                            </div>
                            @if ($e->note && in_array($e->type, ['note', 'status'], true))<p class="mt-0.5 whitespace-pre-line text-sm text-ink-2">{{ $e->note }}</p>@endif
                        </li>
                    @endforeach
                </ol>
                <form wire:submit="addNote" class="mt-5 space-y-2 border-t border-line pt-4">
                    <label for="note" class="label">{{ __('Add a note') }}</label>
                    <textarea id="note" wire:model="note" rows="2" maxlength="2000" class="field"></textarea>
                    @error('note')<p class="text-sm text-critical">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="flex items-center gap-2 text-sm text-ink-2"><input type="checkbox" wire:model="notePublic" class="size-4 accent-[var(--accent)]"> {{ __('Citizen can see this note') }}</label>
                        <button type="submit" class="btn-secondary">{{ __('Add note') }}</button>
                    </div>
                </form>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('Citizen') }}</h2>
                @if ($g->personal_data_purged_at)
                    <p class="mt-1 text-sm text-ink-3">{{ __('Contact details deleted after the retention period.') }}</p>
                @elseif ($showContact)
                    <p class="mt-1 text-ink">{{ $g->citizen_name ?: __('Name not given') }}</p>
                    <a href="tel:+91{{ $g->citizen_mobile }}" class="font-medium tabular-nums text-accent hover:underline">+91 {{ $g->citizen_mobile }}</a>
                    <p class="mt-1 text-xs text-ink-3">{{ __('Use only to follow up on this grievance.') }}</p>
                @else
                    <p class="mt-1 text-sm tabular-nums text-ink-2">{{ $g->maskedMobile() }}</p>
                    <button type="button" wire:click="revealContact" class="mt-2 text-sm text-accent hover:underline">{{ __('Show contact (this is logged)') }}</button>
                @endif
            </section>

            <section class="card space-y-2 p-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('Handled by') }}</h2>
                <form wire:submit="assign" class="flex gap-2">
                    <label for="assign" class="sr-only">{{ __('Officer') }}</label>
                    <select id="assign" wire:model="assignTo" class="field py-1.5">
                        <option value="">{{ __('Not assigned') }}</option>
                        @foreach ($officers as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                    </select>
                    <button type="submit" class="btn-secondary shrink-0">{{ __('Assign') }}</button>
                </form>
                @error('assignTo')<p class="text-sm text-critical">{{ $message }}</p>@enderror
            </section>

            <section class="card space-y-2 p-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('Work') }}</h2>
                @if ($g->work)
                    <a href="{{ route('works.show', $g->work) }}" wire:navigate class="block font-medium text-accent hover:underline">{{ $g->work->name_en }}</a>
                    <div class="text-xs text-ink-3">{{ $g->work->code }} · {{ __('physical :p%', ['p' => $g->work->physical_pct]) }}</div>
                @endif
                <form wire:submit="linkWork" class="flex gap-2">
                    <label for="work-code" class="sr-only">{{ __('Work code') }}</label>
                    <input id="work-code" wire:model="workCode" list="district-works" class="field py-1.5" placeholder="{{ __('Work code') }}">
                    <datalist id="district-works">@foreach ($works as $w)<option value="{{ $w->code }}">{{ $w->name_en }}</option>@endforeach</datalist>
                    <button type="submit" class="btn-secondary shrink-0">{{ $g->work ? __('Change') : __('Link') }}</button>
                </form>
                @error('workCode')<p class="text-sm text-critical">{{ $message }}</p>@enderror
                <p class="text-xs text-ink-3">{{ __('Linking puts the grievance on the work record.') }}</p>
            </section>
        </aside>
    </div>
</div>
