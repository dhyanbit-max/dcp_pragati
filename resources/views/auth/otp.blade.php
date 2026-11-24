<x-layouts.guest>
    <h1 class="text-xl font-semibold text-ink">{{ __('Enter the code') }}</h1>
    <p class="mt-1 text-sm text-ink-2">{{ __('We sent a 6-digit code to :mobile. It is valid for 5 minutes.', ['mobile' => $maskedMobile]) }}</p>

    <form method="POST" action="{{ route('login.otp') }}" class="mt-6 space-y-4" novalidate>
        <x-dev-otp :code="$devOtp ?? null" for="otp" />
        @csrf
        <div>
            <label for="otp" class="label">{{ __('One-time code') }}</label>
            <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   required autofocus class="field tracking-[0.4em] text-lg" aria-describedby="otp-error">
            @error('otp')<p id="otp-error" class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>
        <button class="btn-primary w-full">{{ __('Sign in') }}</button>
    </form>
    <a href="{{ route('login') }}" class="mt-4 inline-block text-sm text-accent hover:underline">{{ __('Start again') }}</a>
</x-layouts.guest>
