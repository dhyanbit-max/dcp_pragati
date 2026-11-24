<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Sign in') }} · DCP Pragati</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased">
<main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('home') }}" class="flex items-center gap-2 text-lg font-semibold text-ink">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-7 text-accent" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M6 16V9"/><path d="M11 16V5"/><path d="M16 16v-4"/><path d="M21 16V8"/></svg>
            DCP Pragati
        </a>
        <form method="POST" action="{{ route('locale') }}">
            @csrf
            <label for="locale" class="sr-only">{{ __('Language') }}</label>
            <select id="locale" name="locale" onchange="this.form.submit()" class="rounded-md border border-line bg-surface py-1 pl-2 pr-7 text-sm text-ink-2">
                @foreach (['en' => 'English', 'as' => 'অসমীয়া', 'bn' => 'বাংলা'] as $code => $name)
                    <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="card p-6 shadow-sm">
        {{ $slot }}
    </div>
    <p class="mt-6 text-center text-xs text-ink-3">{{ __('Transformation & Development Department, Government of Assam') }}</p>
</main>
</body>
</html>
