<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('Progress of development works under the Decentralised Planning schemes of Assam, from geotagged site photos approved by district officers.') }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}DCP Pragati · {{ __('Government of Assam') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-screen flex-col bg-surface font-sans antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 btn-primary">{{ __('Skip to content') }}</a>

<div class="bg-accent text-on-accent">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-1.5 text-xs sm:px-6">
        <span>{{ __('Government of Assam') }} · {{ __('Transformation & Development Department') }}</span>
        <form method="POST" action="{{ route('locale') }}" class="flex items-center gap-1">
            @csrf
            <label for="pub-locale" class="sr-only">{{ __('Language') }}</label>
            <select id="pub-locale" name="locale" onchange="this.form.submit()" class="rounded border-0 bg-white/15 py-0.5 pl-2 pr-6 text-xs text-on-accent focus:ring-2 focus:ring-white/60">
                @foreach (['en' => 'English', 'as' => 'অসমীয়া', 'bn' => 'বাংলা'] as $code => $name)
                    <option value="{{ $code }}" class="text-ink" @selected(app()->getLocale() === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>

<header class="sticky top-0 z-30 border-b border-line bg-surface/90 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 sm:px-6">
        <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold text-ink">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-7 text-accent" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M6 16V9"/><path d="M11 16V5"/><path d="M16 16v-4"/><path d="M21 16V8"/></svg>
            <span class="leading-tight">DCP Pragati<span class="block text-[11px] font-normal text-ink-3">{{ __('Decentralised Planning, Assam') }}</span></span>
        </a>
        <nav aria-label="{{ __('Main') }}" class="ml-auto flex items-center gap-1 text-sm">
            @if (config('dcp.public.grievances'))
                <a href="{{ route('grievances.create') }}" @class(['hidden rounded-md px-3 py-1.5 font-medium sm:inline-block', 'bg-accent-soft text-accent' => request()->routeIs('grievances.create'), 'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('grievances.create')])>{{ __('Raise a grievance') }}</a>
                <a href="{{ route('grievances.track') }}" @class(['hidden rounded-md px-3 py-1.5 font-medium sm:inline-block', 'bg-accent-soft text-accent' => request()->routeIs('grievances.track'), 'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('grievances.track')])>{{ __('Track') }}</a>
            @endif
            <a href="{{ route('manual') }}" @class(['hidden rounded-md px-3 py-1.5 font-medium sm:inline-block', 'bg-accent-soft text-accent' => request()->routeIs('manual'), 'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! request()->routeIs('manual')])>{{ __('User manual') }}</a>
            <a href="{{ auth()->check() ? route('home') : route('login') }}" class="btn-secondary ml-1 py-1.5 text-sm">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
                {{ auth()->check() ? __('Open DCP Pragati') : __('Staff sign in') }}
            </a>
        </nav>
    </div>
</header>

<main id="main" class="flex-1">
    {{ $slot }}
</main>

<footer class="border-t border-line bg-surface-2">
    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 text-sm text-ink-2 sm:grid-cols-3 sm:px-6">
        <div>
            <div class="font-semibold text-ink">DCP Pragati</div>
            <p class="mt-1 text-ink-3">{{ __('A monitoring layer for the Decentralised Planning schemes of Assam. e-Rupantor remains the official record.') }}</p>
        </div>
        <div>
            <div class="font-semibold text-ink">{{ __('For citizens') }}</div>
            <ul class="mt-1 space-y-1">
                @if (config('dcp.public.grievances'))
                    <li><a class="hover:text-accent hover:underline" href="{{ route('grievances.create') }}">{{ __('Raise a grievance') }}</a></li>
                    <li><a class="hover:text-accent hover:underline" href="{{ route('grievances.track') }}">{{ __('Track a grievance') }}</a></li>
                @endif
                <li><a class="hover:text-accent hover:underline" href="{{ route('manual') }}">{{ __('User manual') }}</a></li>
                <li><a class="hover:text-accent hover:underline" href="{{ route('home') }}#privacy">{{ __('Privacy') }}</a></li>
            </ul>
        </div>
        <div>
            <div class="font-semibold text-ink">{{ __('For officers') }}</div>
            <ul class="mt-1 space-y-1">
                <li><a class="hover:text-accent hover:underline" href="{{ route('login') }}">{{ __('Staff sign in') }}</a></li>
                <li><a class="hover:text-accent hover:underline" href="{{ route('password.setup') }}">{{ __('Set or reset your password') }}</a></li>
                <li><a class="hover:text-accent hover:underline" href="{{ route('manual') }}#dpc">{{ __('Approving updates') }}</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-line py-3 text-center text-xs text-ink-3">{{ __('Transformation & Development Department, Government of Assam') }}</div>
</footer>
@livewireScripts
</body>
</html>
