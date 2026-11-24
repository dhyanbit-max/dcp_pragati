@props(['label', 'value', 'hint' => null, 'tone' => null])
@php
    $toneClass = ['warning' => 'text-warning', 'critical' => 'text-critical', 'serious' => 'text-serious', 'good' => 'text-good'][$tone] ?? 'text-ink';
@endphp
<div class="card px-4 py-3">
    <div class="text-xs font-medium text-ink-3">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold tabular-nums {{ $toneClass }}">{{ $value }}</div>
    @if ($hint)
        <div class="mt-0.5 text-xs text-ink-3">{{ $hint }}</div>
    @endif
</div>
