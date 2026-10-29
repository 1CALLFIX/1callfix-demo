@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $navItems = [
        ['label' => 'Home', 'route' => 'seller.dashboard', 'icon' => 'home'],
        ['label' => 'Orders', 'route' => 'seller.orders', 'icon' => 'clipboard'],
        ['label' => 'Products', 'route' => 'seller.products', 'icon' => 'wrench'],
        ['label' => 'Shop', 'route' => 'seller.shop', 'icon' => 'building'],
        ['label' => 'Earnings', 'route' => 'seller.earnings', 'icon' => 'banknotes', 'also' => ['seller.payment-accounts', 'seller.request-payout']],
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
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ route('seller.dashboard') }}" wire:navigate class="truncate text-base font-bold tracking-tight">
                    {{ $platformName }} <span class="font-medium text-blue-600">Seller</span>
                </a>
                <div class="flex shrink-0 items-center gap-4">
                    <x-partner.mode-switch current="commerce" class="hidden text-sm sm:inline" />
                    <form method="POST" action="{{ route('seller.logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">Sign out</button>
                    </form>
                </div>
            </div>
            <nav aria-label="Seller sections" class="mx-auto flex max-w-5xl gap-1 overflow-x-auto px-2 pb-2 sm:px-4">
                @foreach ($navItems as $item)
                    @php $isCurrent = request()->routeIs($item['route'], ...($item['also'] ?? [])); @endphp
                    <a href="{{ route($item['route']) }}" wire:navigate @if ($isCurrent) aria-current="page" @endif
                       @class([
                           'inline-flex min-h-10 flex-1 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-sm font-medium transition',
                           'bg-blue-50 text-blue-700' => $isCurrent,
                           'text-slate-600 hover:bg-slate-50' => ! $isCurrent,
                       ])>
                        <x-icon :name="$item['icon']" class="h-4 w-4" /> {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </header>
    @endauth

    <main id="seller-main" tabindex="-1" class="mx-auto w-full max-w-5xl flex-1 px-4 py-6 focus:outline-none sm:px-6">
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

    @livewireScripts
    @stack('scripts')
</body>
</html>
