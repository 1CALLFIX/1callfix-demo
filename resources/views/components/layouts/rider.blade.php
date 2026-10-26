@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $navItems = [
        ['label' => 'Home', 'route' => 'rider.dashboard', 'icon' => 'home'],
        ['label' => 'History', 'route' => 'rider.history', 'icon' => 'clock'],
        ['label' => 'Earnings', 'route' => 'rider.earnings', 'icon' => 'banknotes', 'also' => ['rider.payment-accounts', 'rider.request-payout']],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.$platformName.' Rider' : $platformName.' Rider' }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2563eb">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @fonts
    {{-- Same entries as the partner layout: FCM web-push registration and the offer ring
         (resources/js/provider-alerts.js — fed by the `provider-alert-offers` event, which the
         rider pages dispatch with parcel offers). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js', 'resources/js/provider-alerts.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased flex flex-col">
    <a href="#rider-main" class="skip-link">Skip to main content</a>

    @auth
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ route('rider.dashboard') }}" wire:navigate class="truncate text-base font-bold tracking-tight">
                    {{ $platformName }} <span class="font-medium text-blue-600">Rider</span>
                </a>
                <form method="POST" action="{{ route('rider.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">Sign out</button>
                </form>
            </div>
            <nav aria-label="Rider sections" class="mx-auto flex max-w-3xl gap-1 px-2 pb-2 sm:px-4">
                @foreach ($navItems as $item)
                    @php $isCurrent = request()->routeIs($item['route'], ...($item['also'] ?? [])); @endphp
                    <a href="{{ route($item['route']) }}" wire:navigate @if ($isCurrent) aria-current="page" @endif
                       @class([
                           'inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium transition',
                           'bg-blue-50 text-blue-700' => $isCurrent,
                           'text-slate-600 hover:bg-slate-50' => ! $isCurrent,
                       ])>
                        <x-icon :name="$item['icon']" class="h-4 w-4" /> {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </header>
    @endauth

    <main id="rider-main" tabindex="-1" class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 focus:outline-none sm:px-6">
        @auth
            {{-- Offer source on pages that don't poll offers themselves (the dashboard does). --}}
            @unless (request()->routeIs('rider.dashboard', 'rider.login'))
                <livewire:rider.offer-watcher />
            @endunless

            <section x-data="providerOfferAlert" x-show="active" style="display: none;"
                     data-respond-url="{{ route('rider.dashboard') }}"
                     data-on-offers-page="{{ request()->routeIs('rider.dashboard') ? '1' : '0' }}"
                     aria-label="Incoming parcel offer"
                     class="mb-4 rounded-2xl border-2 border-amber-400 bg-amber-50 p-4 shadow-lg ring-4 ring-amber-200/70">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-extrabold uppercase tracking-widest text-amber-800">
                            <span role="alert">New parcel offer</span>
                            <span x-show="extra > 0" x-text="'+' + extra + ' more'" class="ml-1 font-semibold normal-case tracking-normal text-amber-700"></span>
                        </p>
                        <span role="timer" x-show="primary" x-text="remaining + 's left'"
                              x-bind:class="remaining <= 10 ? 'bg-rose-600 text-white' : 'bg-amber-200 text-amber-900'"
                              class="rounded-full px-2.5 py-0.5 text-xs font-bold tabular-nums"></span>
                    </div>
                    <template x-if="primary">
                        <p class="mt-1 text-base font-bold text-slate-900">
                            <span x-text="primary.service"></span>
                            <span x-show="primary.price" x-text="'· ' + primary.price" class="font-semibold text-emerald-700"></span>
                        </p>
                    </template>
                    <p x-show="meta" x-text="meta" class="mt-0.5 text-xs text-slate-600"></p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <template x-if="onOffersPage">
                            <p class="font-semibold text-amber-900">Accept or decline below ↓</p>
                        </template>
                        <template x-if="!onOffersPage">
                            <a x-bind:href="respondUrl" wire:navigate class="inline-flex min-h-10 items-center rounded-lg bg-amber-600 px-4 font-semibold text-white hover:bg-amber-700">View &amp; respond →</a>
                        </template>
                        <button type="button" x-show="audioBlocked" x-on:click="enableSound($event)" class="text-xs font-semibold text-amber-800 underline underline-offset-2">
                            Tap to enable ring sound
                        </button>
                    </div>
                </div>
            </section>
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
