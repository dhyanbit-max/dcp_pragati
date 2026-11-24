@props(['code', 'for'])
{{-- Development only (App\Domain\Auth\DevOtp): the OTP that was "sent", with a button to fill it in. --}}
@if ($code)
    <div class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-[var(--warning)] bg-warning-soft px-3 py-2 text-sm" role="note">
        <span class="text-ink">
            <span class="block text-[11px] font-semibold uppercase tracking-wide text-ink-2">{{ __('Development only') }}</span>
            {{ __('Your code is') }} <strong class="font-mono text-base tracking-widest">{{ $code }}</strong>
        </span>
        <button type="button" class="btn-secondary shrink-0 py-1 text-xs"
                onclick="const i = document.getElementById(@js($for)); i.value = @js($code); i.dispatchEvent(new Event('input', { bubbles: true })); i.focus();">
            {{ __('Use this code') }}
        </button>
    </div>
@endif
