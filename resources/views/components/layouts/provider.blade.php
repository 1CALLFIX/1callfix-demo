@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.$platformName.' Partner' : $platformName.' Partner' }}</title>
    <meta name="theme-color" content="#2563eb">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased flex flex-col">
    <a href="#provider-main" class="skip-link">Skip to main content</a>

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="{{ route('provider.dashboard') }}" wire:navigate class="text-base font-bold tracking-tight">
                {{ $platformName }} <span class="font-medium text-blue-600">Partner</span>
            </a>

            @auth
                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('provider.jobs.index') }}" wire:navigate class="text-slate-600 hover:text-slate-900">Jobs</a>
                    <a href="{{ route('provider.earnings') }}" wire:navigate class="text-slate-600 hover:text-slate-900">Earnings</a>
                    <a href="{{ route('provider.history') }}" wire:navigate class="hidden text-slate-600 hover:text-slate-900 sm:inline">History</a>
                    <a href="{{ route('provider.activity') }}" wire:navigate class="hidden text-slate-600 hover:text-slate-900 sm:inline">Activity</a>
                    <form method="POST" action="{{ route('provider.logout') }}">
                        @csrf
                        <button type="submit" class="text-slate-500 hover:text-slate-900">Sign out</button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main id="provider-main" tabindex="-1" class="mx-auto w-full max-w-4xl flex-1 px-4 py-6 focus:outline-none sm:px-6">
        {{ $slot }}
    </main>

    @livewireScripts
    @stack('scripts')
</body>
</html>
