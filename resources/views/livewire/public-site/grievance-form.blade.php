@php use App\Domain\Grievances\GrievanceService; @endphp
<div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div>
            <h1 class="text-2xl font-semibold text-ink sm:text-3xl">{{ __('Raise a grievance') }}</h1>
            <p class="mt-1 text-ink-2">{{ __('About a development work under the Decentralised Planning schemes: roads, culverts, school buildings, community halls, namghars, water supply.') }}</p>

            {{-- Steps --}}
            @php $steps = ['details' => __('Your grievance'), 'verify' => __('Verify mobile'), 'done' => __('Reference number')]; $at = array_search($step, array_keys($steps)); @endphp
            <ol class="mt-6 flex items-center gap-2 text-sm" aria-label="{{ __('Progress') }}">
                @foreach (array_values($steps) as $i => $label)
                    <li class="flex items-center gap-2" @if($i === $at) aria-current="step" @endif>
                        <span @class(['grid size-7 place-items-center rounded-full text-xs font-semibold',
                            'bg-accent text-on-accent' => $i === $at, 'bg-accent-soft text-accent' => $i < $at, 'bg-surface-2 text-ink-3' => $i > $at])>{{ $i < $at ? '✓' : $i + 1 }}</span>
                        <span @class(['hidden sm:inline', 'font-medium text-ink' => $i === $at, 'text-ink-3' => $i !== $at])>{{ $label }}</span>
                        @if ($i < 2)<span class="mx-1 h-px w-6 bg-line sm:w-10" aria-hidden="true"></span>@endif
                    </li>
                @endforeach
            </ol>

            @if ($step === 'details')
                <form wire:submit="sendCode" class="mt-6 space-y-6" novalidate>
                    <fieldset class="card space-y-4 p-5">
                        <legend class="px-1 text-sm font-semibold text-ink">{{ __('Where') }}</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="g-district" class="label">{{ __('District') }} <span class="text-critical">*</span></label>
                                <select id="g-district" wire:model.live="district" class="field" required aria-invalid="{{ $errors->has('district') ? 'true' : 'false' }}">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->name($loc) }}</option>@endforeach
                                </select>
                                @error('district')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="g-co" class="label">{{ __('Co-district (if you know it)') }}</label>
                                <select id="g-co" wire:model="coDistrict" class="field" @disabled(! $district)>
                                    <option value="">{{ __('Not sure') }}</option>
                                    @foreach ($coDistricts as $c)<option value="{{ $c->id }}">{{ $c->name($loc) }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="g-loc" class="label">{{ __('Village, ward or landmark') }}</label>
                            <div class="flex gap-2" x-data="{ busy: false, err: '' }">
                                <input id="g-loc" wire:model="locationText" maxlength="255" class="field" placeholder="{{ __('e.g. near Borgaon LP School, ward 4') }}">
                                <button type="button" class="btn-secondary shrink-0" :disabled="busy"
                                        x-on:click="if (!navigator.geolocation) { err = @js(__('Location is not available on this device.')); return; }
                                            busy = true; err = '';
                                            navigator.geolocation.getCurrentPosition(
                                                (p) => { $wire.set('lat', +p.coords.latitude.toFixed(6)); $wire.set('lng', +p.coords.longitude.toFixed(6)); busy = false; },
                                                () => { err = @js(__('Could not get your location. Type the place instead.')); busy = false; },
                                                { enableHighAccuracy: true, timeout: 15000 })">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                    <span x-text="busy ? @js(__('Locating…')) : @js(__('Use my location'))"></span>
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-ink-3" x-data x-text="$wire.lat ? @js(__('Location added:')) + ' ' + $wire.lat + ', ' + $wire.lng : @js(__('Optional. Helps the office find the place.'))"></p>
                        </div>
                        <div>
                            <label for="g-work" class="label">{{ __('Which work (name or code on the signboard)') }}</label>
                            <input id="g-work" wire:model="workReference" maxlength="120" class="field" placeholder="{{ __('e.g. Construction of RCC culvert on Borgaon road') }}">
                        </div>
                    </fieldset>

                    <fieldset class="card space-y-4 p-5">
                        <legend class="px-1 text-sm font-semibold text-ink">{{ __('What is wrong') }}</legend>
                        <div role="radiogroup" aria-labelledby="g-cat-label">
                            <span id="g-cat-label" class="label">{{ __('Type of problem') }} <span class="text-critical">*</span></span>
                            <div class="mt-1 grid gap-2 sm:grid-cols-2">
                                @foreach (config('dcp.grievances.categories') as $c)
                                    <label @class(['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                                        'border-accent bg-accent-soft text-ink' => $category === $c, 'border-line text-ink-2 hover:border-line-strong' => $category !== $c])>
                                        <input type="radio" wire:model.live="category" value="{{ $c }}" class="accent-[var(--accent)]">
                                        {{ GrievanceService::categoryLabel($c) }}
                                    </label>
                                @endforeach
                            </div>
                            @error('category')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="g-subject" class="label">{{ __('In one line') }} <span class="text-critical">*</span></label>
                            <input id="g-subject" wire:model="subject" maxlength="150" class="field" placeholder="{{ __('e.g. Road surface broken within two months') }}">
                            @error('subject')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                        </div>
                        <div x-data="{ n: 0 }">
                            <label for="g-desc" class="label">{{ __('Details') }} <span class="text-critical">*</span></label>
                            <textarea id="g-desc" wire:model="description" rows="5" maxlength="2000" class="field" x-on:input="n = $event.target.value.length"
                                      placeholder="{{ __('What did you see, since when, and how does it affect people?') }}"></textarea>
                            <div class="mt-1 flex justify-between text-xs text-ink-3"><span>{{ __('At least 20 characters.') }}</span><span x-text="n + ' / 2000'"></span></div>
                            @error('description')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="g-photo" class="label">{{ __('Photo (optional)') }}</label>
                            <input id="g-photo" type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" capture="environment"
                                   class="block w-full text-sm text-ink-2 file:mr-3 file:rounded-md file:border-0 file:bg-accent-soft file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-accent">
                            <div wire:loading wire:target="photo" class="mt-1 text-xs text-ink-3">{{ __('Uploading…') }}</div>
                            @if ($photo && ! $errors->has('photo'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Your photo') }}" class="mt-2 h-32 rounded-lg border border-line object-cover">
                            @endif
                            @error('photo')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                            <p class="mt-1 text-xs text-ink-3">{{ __('Of the work, not of people. Up to :mb MB.', ['mb' => round(config('dcp.grievances.max_photo_kb') / 1024)]) }}</p>
                        </div>
                    </fieldset>

                    <fieldset class="card space-y-4 p-5">
                        <legend class="px-1 text-sm font-semibold text-ink">{{ __('How we reach you') }}</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="g-mobile" class="label">{{ __('Mobile number') }} <span class="text-critical">*</span></label>
                                <div class="flex">
                                    <span class="inline-flex items-center rounded-l-md border border-r-0 border-line bg-surface-2 px-2.5 text-sm text-ink-3">+91</span>
                                    <input id="g-mobile" wire:model="mobile" inputmode="numeric" autocomplete="tel-national" maxlength="14" class="field rounded-l-none" placeholder="98XXXXXXXX">
                                </div>
                                @error('mobile')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="g-name" class="label">{{ __('Your name (optional)') }}</label>
                                <input id="g-name" wire:model="name" maxlength="120" autocomplete="name" class="field">
                            </div>
                        </div>
                        <label class="flex items-start gap-2 text-sm text-ink-2">
                            <input type="checkbox" wire:model="consent" class="mt-0.5 size-4 accent-[var(--accent)]">
                            <span>{{ __('I agree that my mobile number (and name, if given) is used to verify me and to send me SMS updates about this grievance. Only the officers handling it can see it. It is deleted :y years after the grievance is closed.', ['y' => round(config('dcp.grievances.retention_days') / 365)]) }}</span>
                        </label>
                        @error('consent')<p class="text-sm text-critical">{{ $message }}</p>@enderror
                    </fieldset>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-primary px-5 py-2.5" wire:loading.attr="disabled" wire:target="sendCode,photo">
                            {{ __('Send code to my mobile') }}
                        </button>
                        <span wire:loading wire:target="sendCode" class="text-sm text-ink-3">{{ __('Sending…') }}</span>
                    </div>
                </form>
            @elseif ($step === 'verify')
                <form wire:submit="verify" class="card mt-6 max-w-md space-y-4 p-6">
                    <h2 class="text-lg font-semibold text-ink">{{ __('Enter the code') }}</h2>
                    @if ($notice)<p class="text-sm text-ink-2" role="status">{{ $notice }}</p>@endif
                    <x-dev-otp :code="\App\Domain\Auth\DevOtp::peek($mobile)" for="g-code" />
                    <div>
                        <label for="g-code" class="label">{{ __('6-digit code') }}</label>
                        <input id="g-code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                               class="field text-center text-2xl tracking-[0.5em] tabular-nums">
                        @error('code')<p class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
                        @foreach (['district', 'category', 'subject', 'description', 'mobile', 'consent', 'photo'] as $f)
                            @error($f)<p class="mt-1 text-sm text-critical">{{ $message }} <button type="button" wire:click="edit" class="underline">{{ __('Edit') }}</button></p>@enderror
                        @endforeach
                    </div>
                    <button type="submit" class="btn-primary w-full justify-center py-2.5" wire:loading.attr="disabled">{{ __('Verify and submit') }}</button>
                    <div class="flex justify-between text-sm">
                        <button type="button" wire:click="edit" class="text-accent hover:underline">← {{ __('Change details') }}</button>
                        <button type="button" wire:click="resend" class="text-accent hover:underline">{{ __('Send a new code') }}</button>
                    </div>
                </form>
            @else
                <div class="card mt-6 max-w-lg p-6 text-center" role="status">
                    <div class="mx-auto grid size-14 place-items-center rounded-full bg-good-soft text-good">
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
                    </div>
                    <h2 class="mt-4 text-xl font-semibold text-ink">{{ __('Your grievance is registered') }}</h2>
                    <p class="mt-1 text-ink-2">{{ __('Keep this reference number. We have also sent it to you by SMS.') }}</p>
                    <div class="mt-4 rounded-lg border border-dashed border-line-strong bg-surface-2 px-4 py-3 font-mono text-2xl font-semibold tracking-wider text-ink" x-data>
                        {{ $ref }}
                        <button type="button" class="ml-2 align-middle text-sm font-sans font-medium text-accent hover:underline"
                                x-on:click="navigator.clipboard?.writeText(@js($ref)); $el.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
                    </div>
                    <p class="mt-4 text-sm text-ink-2">{{ __('The district office will acknowledge it within :a days and answer within :r days.', ['a' => config('dcp.grievances.acknowledge_days'), 'r' => config('dcp.grievances.resolve_days')]) }}</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('grievances.track', ['ref' => $ref]) }}" class="btn-primary">{{ __('Track it') }}</a>
                        <a href="{{ route('home') }}" class="btn-secondary">{{ __('Back to home') }}</a>
                    </div>
                </div>
            @endif
        </div>

        <aside class="space-y-4 lg:pt-14">
            <div class="card p-5">
                <h2 class="font-semibold text-ink">{{ __('What happens next') }}</h2>
                <ol class="mt-3 space-y-3 text-sm text-ink-2">
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-accent-soft text-xs font-semibold text-accent">1</span>{{ __('You get a reference number by SMS.') }}</li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-accent-soft text-xs font-semibold text-accent">2</span>{{ __('The District Planning Cell acknowledges it within :d days.', ['d' => config('dcp.grievances.acknowledge_days')]) }}</li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-accent-soft text-xs font-semibold text-accent">3</span>{{ __('An officer checks the work, often with a site visit.') }}</li>
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-accent-soft text-xs font-semibold text-accent">4</span>{{ __('You get an SMS when it is resolved, with what was done.') }}</li>
                </ol>
            </div>
            <div class="rounded-xl border border-line bg-surface-2 p-5 text-sm text-ink-2">
                <h2 class="font-semibold text-ink">{{ __('Not for these') }}</h2>
                <p class="mt-1">{{ __('Personal benefits, jobs, payments to contractors, or emergencies. For emergencies call 112.') }}</p>
            </div>
        </aside>
    </div>
</div>
