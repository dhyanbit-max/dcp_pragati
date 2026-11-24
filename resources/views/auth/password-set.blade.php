<x-layouts.guest>
    <h1 class="text-xl font-semibold text-ink">{{ __('Choose a password') }}</h1>
    <p class="mt-1 text-sm text-ink-2">{{ __('If :mobile has web access, we sent a code to it. The code is valid for 5 minutes.', ['mobile' => $maskedMobile]) }}</p>

    <form method="POST" action="{{ route('password.setup.verify') }}" class="mt-6 space-y-4" novalidate>
        <x-dev-otp :code="$devOtp ?? null" for="otp" />
        @csrf
        <div>
            <label for="otp" class="label">{{ __('One-time code') }}</label>
            <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   required autofocus class="field tracking-[0.4em] text-lg" aria-describedby="otp-error">
            @error('otp')<p id="otp-error" class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="label">{{ __('New password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="field" aria-describedby="password-help password-error">
            <p id="password-help" class="mt-1 text-xs text-ink-3">{{ __('At least 10 characters, with letters and numbers.') }}</p>
            @error('password')<p id="password-error" class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password_confirmation" class="label">{{ __('Repeat the new password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="field">
        </div>
        <button class="btn-primary w-full">{{ __('Set password') }}</button>
    </form>
    <a href="{{ route('password.setup') }}" class="mt-4 inline-block text-sm text-accent hover:underline">{{ __('Send a new code') }}</a>
</x-layouts.guest>
