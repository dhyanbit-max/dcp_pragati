@props(['at', 'by' => null, 'never' => null])
{{-- When the last accepted update was captured: calendar days (app timezone), the time, and who submitted it. --}}
@php
    $at = $at ? \Illuminate\Support\Carbon::parse($at)->setTimezone(config('app.timezone')) : null;
    $days = $at ? max(0, (int) $at->copy()->startOfDay()->diffInDays(today(), false)) : null;
@endphp
@if ($at)
    <time datetime="{{ $at->toIso8601String() }}" title="{{ $at->translatedFormat('l, j F Y, g:i A') }}" {{ $attributes->merge(['class' => 'inline-block']) }}>
        <span class="block">{{ match (true) { $days === 0 => __('Today'), $days === 1 => __('Yesterday'), default => __(':d days ago', ['d' => $days]) } }}</span>
        <span class="block text-xs text-ink-3">{{ $at->translatedFormat($days <= 1 ? 'g:i A' : ($at->isCurrentYear() ? 'j M, g:i A' : 'j M Y, g:i A')) }}</span>
        @if ($by)<span class="block text-xs text-ink-3">{{ __('by :name', ['name' => $by]) }}</span>@endif
    </time>
@else
    <span {{ $attributes }}>{{ $never ?? __('Never') }}</span>
@endif
