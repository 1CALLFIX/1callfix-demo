@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    // Rider app 2026-11-04: an app-style bottom bar (like the customer app). Home runs every job — parcel, shop,
    // food and return pickups — so there is no separate Shop tab any more.
    $navItems = [
        ['label' => 'Home', 'route' => 'rider.dashboard', 'icon' => 'home', 'also' => ['rider.shop']],
        ['label' => 'Earnings', 'route' => 'rider.earnings', 'icon' => 'banknotes', 'also' => ['rider.payment-accounts', 'rider.request-payout']],
        ['label' => 'History', 'route' => 'rider.history', 'icon' => 'clock'],
        ['label' => 'More', 'route' => 'rider.more', 'icon' => 'squares'],
    ];
    $riderNow = auth()->check() ? \App\Models\FieldWorker::where('user_id', auth()->id())->first(['id', 'is_online']) : null;
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
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ route('rider.dashboard') }}" wire:navigate class="truncate text-base font-bold tracking-tight">
                    {{ $platformName }} <span class="font-medium text-blue-600">Rider</span>
                </a>
                {{-- Partner apps 2026-11-04: sign out and mode switch live on the More tab; Home has its own big switch. --}}
                @if ($riderNow && ! request()->routeIs('rider.dashboard'))
                    <a href="{{ route('rider.dashboard') }}" wire:navigate @class(['inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold',
                        'bg-emerald-100 text-emerald-800' => $riderNow->is_online, 'bg-slate-100 text-slate-600' => ! $riderNow->is_online])>
                        <span @class(['h-2 w-2 rounded-full', 'bg-emerald-500' => $riderNow->is_online, 'bg-slate-400' => ! $riderNow->is_online])></span>
                        {{ $riderNow->is_online ? 'Online' : 'Offline' }}
                    </a>
                @endif
            </div>
        </header>
    @endauth

    <main id="rider-main" tabindex="-1" class="mx-auto w-full max-w-3xl flex-1 px-4 py-5 focus:outline-none sm:px-6" @auth style="padding-bottom: calc(5.5rem + env(safe-area-inset-bottom, 0px));" @endauth>
        @auth
            {{-- Offer source on pages that don't poll offers themselves (the dashboard does). --}}
            @unless (request()->routeIs('rider.dashboard', 'rider.login', 'rider.shop'))
                <livewire:rider.offer-watcher />
            @endunless

            <section x-data="providerOfferAlert" x-show="active" style="display: none;"
                     data-respond-url="{{ route('rider.dashboard') }}"
                     data-on-offers-page="{{ request()->routeIs('rider.dashboard', 'rider.shop') ? '1' : '0' }}"
                     aria-label="Incoming delivery offer"
                     class="mb-4 rounded-2xl border-2 border-amber-400 bg-amber-50 p-4 shadow-lg ring-4 ring-amber-200/70">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-extrabold uppercase tracking-widest text-amber-800">
                            <span role="alert">New delivery offer</span>
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

    @auth
        <x-partner.bottom-nav :items="$navItems" label="Rider sections" />
    @endauth

    @livewireScripts
    @stack('scripts')
</body>
</html>
