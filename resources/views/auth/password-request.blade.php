<x-layouts.guest>
    <h1 class="text-xl font-semibold text-ink">{{ __('Set or reset your password') }}</h1>
    <p class="mt-1 text-sm text-ink-2">{{ __('Enter the mobile number registered with DCP Pragati. If it has web access, we will send a one-time code to it.') }}</p>

    <form method="POST" action="{{ route('password.setup') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <div>
            <label for="mobile" class="label">{{ __('Mobile number') }}</label>
            <input id="mobile" name="mobile" type="tel" inputmode="numeric" autocomplete="username" maxlength="10"
                   value="{{ old('mobile') }}" required autofocus class="field" aria-describedby="mobile-error">
            @error('mobile')<p id="mobile-error" class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>
        <button class="btn-primary w-full">{{ __('Send code') }}</button>
    </form>
    <a href="{{ route('login') }}" class="mt-4 inline-block text-sm text-accent hover:underline">{{ __('Back to sign in') }}</a>
</x-layouts.guest>
