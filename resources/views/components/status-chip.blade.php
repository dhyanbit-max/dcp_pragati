@props(['status'])
{{-- Status never by colour alone: every chip has an icon and a text label (WCAG; dataviz status rule). --}}
@php
    $map = [
        'completed'        => ['bg-good-soft text-good', 'M5 12l4 4 10-10', __('Completed')],
        'in_progress'      => ['bg-accent-soft text-accent', 'M12 6v6l4 2', __('In progress')],
        'not_started'      => ['bg-neutral-soft text-ink-2', 'M6 12h12', __('Not started')],
        'stalled'          => ['bg-critical-soft text-critical', 'M8 6v12M16 6v12', __('Stalled')],
        'cancelled'        => ['bg-neutral-soft text-ink-3', 'M6 6l12 12M18 6L6 18', __('Cancelled')],
        'delayed'          => ['bg-warning-soft text-warning', 'M12 7v5m0 4h.01', __('Delayed')],
        'stale'            => ['bg-serious-soft text-serious', 'M12 6v6l3 3', __('No recent update')],
        'mismatch'         => ['bg-serious-soft text-serious', 'M4 17l6-6 4 4 6-6', __('Spend ahead of progress')],
        'pending_approval' => ['bg-warning-soft text-warning', 'M12 6v6l4 2', __('Pending approval')],
        'accepted'         => ['bg-good-soft text-good', 'M5 12l4 4 10-10', __('Accepted')],
        'rejected'         => ['bg-critical-soft text-critical', 'M6 6l12 12M18 6L6 18', __('Rejected')],
        'returned'         => ['bg-serious-soft text-serious', 'M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3', __('Returned')],
        'flagged'          => ['bg-warning-soft text-warning', 'M5 21V4h11l-1 4 1 4H5', __('Flagged')],
    ];
    [$classes, $path, $label] = $map[$status] ?? ['bg-neutral-soft text-ink-2', 'M6 12h12', $status];
@endphp
<span {{ $attributes->merge(['class' => "chip $classes"]) }}>
    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
    {{ $label }}
</span>
