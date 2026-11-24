<x-layouts.guest>
    <h1 class="text-xl font-semibold text-ink">{{ __('Sign in') }}</h1>
    <p class="mt-1 text-sm text-ink-2">{{ __('Real-time monitoring of Decentralised Planning schemes') }}</p>

    @if (session('status'))
        <div role="status" class="mt-4 rounded-md border border-line bg-good-soft px-3 py-2 text-sm text-good">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <div>
            <label for="mobile" class="label">{{ __('Mobile number') }}</label>
            <input id="mobile" name="mobile" type="tel" inputmode="numeric" autocomplete="username" maxlength="10"
                   value="{{ old('mobile') }}" required autofocus class="field" aria-describedby="mobile-error">
            @error('mobile')<p id="mobile-error" class="mt-1 text-sm text-critical">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="label">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="field">
        </div>
        <button class="btn-primary w-full">{{ __('Continue') }}</button>
    </form>
    <p class="mt-4 text-xs text-ink-3">{{ __('We will send a one-time code to your mobile to finish signing in.') }}</p>
    <a href="{{ route('password.setup') }}" class="mt-2 inline-block text-sm text-accent hover:underline">{{ __('First time here, or forgot your password?') }}</a>
</x-layouts.guest>
