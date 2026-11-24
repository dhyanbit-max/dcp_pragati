<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}DCP Pragati</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 btn-primary">{{ __('Skip to content') }}</a>

@php
    $user = auth()->user();
    $pending = $user?->can(\App\Domain\Access\Permission::APPROVE_PROGRESS)
        ? \App\Models\ProgressUpdate::query()->pending()->count() : null;
    $openAlerts = $user?->can(\App\Domain\Access\Permission::HANDLE_ALERTS)
        ? \App\Models\Alert::query()->where('status', 'open')->count() : null;
    $openGrievances = config('dcp.public.grievances') && $user?->can(\App\Domain\Access\Permission::HANDLE_GRIEVANCES)
        ? \App\Models\Grievance::query()->open()->count() : null;
    $nav = [
        ['dashboard', __('Dashboard'), 'dashboard'],
        ['works.index', __('Works'), 'works.*'],
        ['map', __('Map'), 'map'],
    ];
@endphp

<header class="border-b border-line bg-surface">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold text-ink">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M6 16V9"/><path d="M11 16V5"/><path d="M16 16v-4"/><path d="M21 16V8"/></svg>
            <span>DCP Pragati</span>
        </a>

        <nav aria-label="{{ __('Main') }}" class="order-3 -mx-1 flex w-full gap-1 overflow-x-auto sm:order-none sm:w-auto">
            @foreach ($nav as [$route, $label, $pattern])
                <a href="{{ route($route) }}" @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                    'bg-accent-soft text-accent' => request()->routeIs($pattern),
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs($pattern),
                ]) @if(request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            @if ($pending !== null)
                <a href="{{ route('approvals') }}" @class([
                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                    'bg-accent-soft text-accent' => request()->routeIs('approvals'),
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('approvals'),
                ])>
                    {{ __('Approvals') }}
                    @if ($pending > 0)
                        <span class="rounded-full bg-accent px-1.5 text-xs text-on-accent tabular-nums">{{ $pending }}</span>
                    @endif
                </a>
            @endif
            @if ($openAlerts !== null)
                <a href="{{ route('alerts') }}" @class([
                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                    'bg-accent-soft text-accent' => request()->routeIs('alerts'),
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('alerts'),
                ])>
                    {{ __('Alerts') }}
                    @if ($openAlerts > 0)
                        <span class="rounded-full bg-[var(--critical)] px-1.5 text-xs text-white tabular-nums">{{ $openAlerts }}</span>
                    @endif
                </a>
            @endif
            @if ($openGrievances !== null)
                <a href="{{ route('grievances.index') }}" @class([
                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                    'bg-accent-soft text-accent' => request()->routeIs('grievances.index', 'grievances.show'),
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('grievances.index', 'grievances.show'),
                ])>
                    {{ __('Grievances') }}
                    @if ($openGrievances > 0)
                        <span class="rounded-full bg-warning-soft px-1.5 text-xs font-semibold text-warning tabular-nums">{{ $openGrievances }}</span>
                    @endif
                </a>
            @endif
            @foreach ([['bulk-edit', __('Bulk edit'), \App\Domain\Access\Permission::BULK_EDIT], ['reports', __('Reports'), \App\Domain\Access\Permission::RUN_REPORTS], ['aq', __('AQ desk'), \App\Domain\Access\Permission::AQ_DESK]] as [$r, $label, $perm])
                @can($perm)
                    <a href="{{ route($r) }}" @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                        'bg-accent-soft text-accent' => request()->routeIs($r),
                        'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs($r),
                    ])>{{ $label }}</a>
                @endcan
            @endforeach
            @can(\App\Domain\Access\Permission::MANAGE_MASTERS)
                <a href="{{ route('import') }}" @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                    'bg-accent-soft text-accent' => request()->routeIs('import'),
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('import'),
                ])>{{ __('Import') }}</a>
            @endcan
        </nav>

        <div class="ml-auto flex items-center gap-3">
            <form method="POST" action="{{ route('locale') }}">
                @csrf
                <label for="locale" class="sr-only">{{ __('Language') }}</label>
                <select id="locale" name="locale" onchange="this.form.submit()" class="rounded-md border border-line bg-surface py-1 pl-2 pr-7 text-sm text-ink-2">
                    @foreach (['en' => 'English', 'as' => 'অসমীয়া', 'bn' => 'বাংলা'] as $code => $name)
                        <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
            <button type="button" onclick="window.dcpToggleTheme()" class="rounded-md p-1.5 text-ink-2 hover:bg-surface-2" aria-label="{{ __('Switch light or dark theme') }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z"/></svg>
            </button>
            @if ($user)
                <div class="hidden text-right text-xs leading-tight md:block">
                    <div class="font-medium text-ink">{{ $user->name }}</div>
                    <div class="text-ink-3">{{ __(str_replace('_', ' ', $user->getRoleNames()->first() ?? '')) }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn-secondary py-1.5">{{ __('Sign out') }}</button>
                </form>
            @endif
        </div>
    </div>
</header>

<main id="main" class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
    {{ $slot }}
</main>

<footer class="mx-auto max-w-7xl px-4 pb-8 pt-2 text-xs text-ink-3 sm:px-6">
    {{ __('Transformation & Development Department, Government of Assam') }} · {{ __('Data as of') }} {{ now()->format('d M Y, H:i') }}
</footer>

@livewireScripts
</body>
</html>
