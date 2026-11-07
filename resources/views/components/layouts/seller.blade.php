@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    // Food F1: a restaurant owner sees Menu / Add-ons / Restaurant instead of Products / Shop.
    $isRestaurant = auth()->check() && \App\Models\Store::whereHas('provider', fn ($q) => $q->where('user_id', auth()->id()))
        ->when((int) request()->query('store'), fn ($q, $id) => $q->whereKey($id))
        ->orderBy('id')->value('module') === \App\Support\Modules::FOOD; // same shop InteractsWithSeller::shop() picks
    // Partner apps 2026-11-04: an app-style bottom bar with four daily tabs; Shop/Restaurant details, Add-ons, bank
    // accounts, mode switch and sign out moved to More (the old top row scrolled sideways on a phone).
    $navItems = [
        ['label' => 'Home', 'route' => 'seller.dashboard', 'icon' => 'home'],
        ['label' => 'Orders', 'route' => 'seller.orders', 'icon' => 'clipboard'],
        ['label' => $isRestaurant ? 'Menu' : 'Products', 'route' => 'seller.products', 'icon' => 'tag', 'also' => ['seller.products.add-many', 'seller.products.choices']],
        ['label' => 'Earnings', 'route' => 'seller.earnings', 'icon' => 'banknotes', 'also' => ['seller.payment-accounts', 'seller.request-payout']],
        ['label' => 'More', 'route' => 'seller.more', 'icon' => 'squares', 'also' => ['seller.shop', 'seller.add-ons']],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.$platformName.' Seller' : $platformName.' Seller' }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2563eb">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth<meta name="cf-signed-in" content="{{ auth()->id() }}">@endauth {{-- read by resources/js/native-app.js (Android apps only) --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @fonts
    {{-- FCM web-push registration, the same entry the partner and rider layouts load: new-order pushes reach a
         seller with the portal closed. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased flex flex-col">
    <a href="#seller-main" class="skip-link">Skip to main content</a>

    @auth
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ route('seller.dashboard') }}" wire:navigate class="truncate text-base font-bold tracking-tight">
                    {{ $platformName }} <span class="font-medium text-blue-600">{{ $isRestaurant ? 'Restaurant' : 'Seller' }}</span>
                </a>
            </div>
        </header>
    @endauth

    <main id="seller-main" tabindex="-1" class="mx-auto w-full max-w-5xl flex-1 px-4 py-5 focus:outline-none sm:px-6" @auth style="padding-bottom: calc(5.5rem + env(safe-area-inset-bottom, 0px));" @endauth>
        @auth
            @unless (request()->routeIs('seller.login'))
                <livewire:seller.order-watcher />
            @endunless
        @endauth

        {{ $slot }}

        @auth
            <x-ui.app-version class="mt-10 text-center" />
        @endauth
    </main>

    @auth
        <x-partner.bottom-nav :items="$navItems" label="Seller sections" />
    @endauth

    @livewireScripts
    {{-- "Saved" / "Not saved" beside the Save button (owner 2026-11-07), same as admin. --}}
    <script data-navigate-once src="{{ asset('js/admin-save-feedback.js') }}?v={{ @filemtime(public_path('js/admin-save-feedback.js')) }}"></script>
    @stack('scripts')
</body>
</html>
