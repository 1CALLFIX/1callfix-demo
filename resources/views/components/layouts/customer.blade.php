@props([
    'title' => null,
    'metaDescription' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) resolve to a
         real value on notched/gesture-bar devices; without it the sticky
         bottom navigation sits underneath the iOS home indicator. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="{{ $metaDescription ?? $platformName.' — verified local professionals for repairs, installation and maintenance.' }}">
    <title>{{ $title ? $title.' · '.$platformName : $platformName }}</title>

    {{-- PWA foundation: the manifest makes the app installable, theme-color
         paints the mobile browser chrome in the brand blue. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $platformName }}">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-maskable.svg') }}">

    @fonts
    {{-- The real Vite pipeline, deliberately NOT the cdn.tailwindcss.com
         script layouts/admin.blade.php still loads. That CDN build compiles
         Tailwind in the browser on every page load — a development-only
         tool, and a material first-paint cost on a consumer-facing page. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-white text-slate-900 antialiased flex flex-col">
    {{-- Keyboard users tabbing from the top of the document can jump past the
         whole header into the page content (WCAG 2.1 AA 2.4.1). Visually
         hidden until focused — see .skip-link in resources/css/app.css. --}}
    <a href="#customer-main" class="skip-link">Skip to main content</a>

    <x-customer.header />

    <main id="customer-main" tabindex="-1" class="flex-1 focus:outline-none">
        {{ $slot }}
    </main>

    <x-customer.footer />

    {{-- Mobile-only sticky navigation. Last in source order because it is
         fixed-position chrome, not document content. --}}
    <x-customer.bottom-nav />

    @livewireScripts
    {{-- Auth screens push the Firebase JS SDK bundle here so it loads only
         where phone-OTP / Google sign-in is actually used, not on every
         customer page. --}}
    @stack('scripts')
</body>
</html>
