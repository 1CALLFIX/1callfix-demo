@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    // Hotels H1: the hotel portal, same app-style bottom bar as the seller portal.
    $navItems = [
        ['label' => 'Home', 'route' => 'hotel.dashboard', 'icon' => 'home'],
        ['label' => 'Rooms', 'route' => 'hotel.rooms', 'icon' => 'building'],
        ['label' => 'Calendar', 'route' => 'hotel.calendar', 'icon' => 'clock'],
        ['label' => 'Hotel', 'route' => 'hotel.details', 'icon' => 'squares'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.$platformName.' Hotel' : $platformName.' Hotel' }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2563eb">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth<meta name="cf-signed-in" content="{{ auth()->id() }}">@endauth {{-- read by resources/js/native-app.js (Android apps only) --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @fonts
    {{-- FCM web-push registration, same as the partner and seller layouts: booking pushes reach a hotel with the portal closed. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased flex flex-col">
    <a href="#hotel-main" class="skip-link">Skip to main content</a>

    @auth
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ route('hotel.dashboard') }}" wire:navigate class="truncate text-base font-bold tracking-tight">
                    {{ $platformName }} <span class="font-medium text-blue-600">Hotel</span>
                </a>
                <form method="POST" action="{{ route('provider.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-slate-600 hover:text-slate-900">Sign out</button>
                </form>
            </div>
        </header>
    @endauth

    <main id="hotel-main" tabindex="-1" class="mx-auto w-full max-w-5xl flex-1 px-4 py-5 focus:outline-none sm:px-6" @auth style="padding-bottom: calc(5.5rem + env(safe-area-inset-bottom, 0px));" @endauth>
        {{ $slot }}

        @auth
            <x-ui.app-version class="mt-10 text-center" />
        @endauth
    </main>

    @auth
        <x-partner.bottom-nav :items="$navItems" label="Hotel sections" />
    @endauth

    @livewireScripts
    {{-- "Saved" / "Not saved" beside the Save button (owner 2026-11-07), same as admin. --}}
    <script data-navigate-once src="{{ asset('js/admin-save-feedback.js') }}?v={{ @filemtime(public_path('js/admin-save-feedback.js')) }}"></script>
    @stack('scripts')
</body>
</html>
