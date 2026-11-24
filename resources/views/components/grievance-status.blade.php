@props(['status'])
{{-- Grievance status: icon + text, never colour alone. --}}
@php
    $map = [
        'submitted' => ['bg-warning-soft text-warning', 'M12 7v5m0 4h.01'],
        'acknowledged' => ['bg-accent-soft text-accent', 'M5 12l4 4 10-10'],
        'in_progress' => ['bg-accent-soft text-accent', 'M12 6v6l4 2'],
        'resolved' => ['bg-good-soft text-good', 'M5 12l4 4 10-10'],
        'rejected' => ['bg-neutral-soft text-ink-2', 'M6 6l12 12M18 6L6 18'],
    ];
    [$classes, $path] = $map[$status] ?? ['bg-neutral-soft text-ink-2', 'M6 12h12'];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap $classes"]) }}>
    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
    {{ \App\Domain\Grievances\GrievanceService::statusLabel($status) }}
</span>
