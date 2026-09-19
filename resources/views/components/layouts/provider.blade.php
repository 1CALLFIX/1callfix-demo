@props([
    'title' => null,
])

@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $user = auth()->user();

    /*
     | Provider Mobile Nav session — every provider-facing route, in one
     | place, shared by both the desktop inline nav and the mobile drawer
     | below. Previously two of these (History, Activity) were rendered
     | `hidden sm:inline` with no other way to reach them under 640px —
     | there was no hamburger, no drawer, nothing. This array is now the
     | single source of truth so the two can never drift again.
     |
     | "Transactions" and "payout" are NOT listed here — no such
     | provider-facing screens exist in this codebase today (Payouts is an
     | admin-only Finance screen). Earnings already covers wallet balance +
     | a per-job ledger, the closest real equivalent.
     */
    $navItems = [
        ['label' => 'Dashboard', 'route' => 'provider.dashboard', 'icon' => 'home'],
        ['label' => 'Job Offers', 'route' => 'provider.jobs.index', 'icon' => 'clipboard'],
        ['label' => 'History', 'route' => 'provider.history', 'icon' => 'clock'],
        ['label' => 'Earnings', 'route' => 'provider.earnings', 'icon' => 'banknotes'],
        ['label' => 'Activity', 'route' => 'provider.activity', 'icon' => 'activity'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · '.$platformName.' Partner' : $platformName.' Partner' }}</title>
    <meta name="theme-color" content="#2563eb">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- PWA manifest — needed for installability and (on iOS) for closed-app
         web push to work at all. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @fonts
    {{-- push-notifications.js: FCM web token registration (Phase 2). Inert
         no-op unless VITE_FIREBASE_* + VITE_FIREBASE_VAPID_KEY are built in. --}}
    {{-- provider-alerts.js: foreground job-offer ring + status chime. A Vite
         entry in <head> on purpose — wire:navigate keeps head modules, so it
         is evaluated once per document. As a raw <body> script it was
         re-run on every visit (duplicate declarations, listeners, timers). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js', 'resources/js/provider-alerts.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased flex flex-col">
    <a href="#provider-main" class="skip-link">Skip to main content</a>

    @auth
        {{-- x-data scoped to the whole header: the hamburger button and the
             drawer it opens both need to share `open`, and Alpine is already
             a dependency of this exact layout (see the online-toggle chip's
             go-online button, and Dashboard's own copy of it) — no new
             library. --}}
        <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <header class="border-b border-slate-200 bg-white">
                <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                    <div class="flex min-w-0 items-center gap-2">
                        {{-- Hamburger — mobile/tablet only. The inline nav
                             below takes over at `lg`, matching the
                             breakpoint the equivalent customer-side nav
                             collapse already uses (components/customer/
                             bottom-nav.blade.php: `lg:hidden`). --}}
                        <button type="button" x-on:click="open = true"
                                class="-ml-1 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden"
                                aria-label="Open menu" aria-haspopup="true" x-bind:aria-expanded="open">
                            <x-icon name="bars-3" class="h-6 w-6" />
                        </button>

                        <a href="{{ route('provider.dashboard') }}" wire:navigate class="truncate text-base font-bold tracking-tight">
                            {{ $platformName }} <span class="font-medium text-blue-600">Partner</span>
                        </a>
                    </div>

                    {{-- Desktop/tablet inline nav — every section, always
                         visible, nothing hidden past a breakpoint anymore. --}}
                    <nav aria-label="Primary" class="hidden items-center gap-4 text-sm lg:flex">
                        @foreach ($navItems as $item)
                            @php $isCurrent = request()->routeIs($item['route']); @endphp
                            <a href="{{ route($item['route']) }}" wire:navigate
                               @if ($isCurrent) aria-current="page" @endif
                               @class([
                                   'rounded-md px-2 py-1.5 font-medium transition',
                                   'text-blue-700' => $isCurrent,
                                   'text-slate-600 hover:text-slate-900' => ! $isCurrent,
                               ])>{{ $item['label'] }}</a>
                        @endforeach
                    </nav>

                    <div class="flex shrink-0 items-center gap-3">
                        <livewire:provider.online-toggle />

                        <form method="POST" action="{{ route('provider.logout') }}" class="hidden lg:block">
                            @csrf
                            <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">Sign out</button>
                        </form>
                    </div>
                </div>
            </header>

            {{-- ===================== Mobile slide-out drawer ===================== --}}
            <div x-show="open" class="lg:hidden" style="display: none;">
                {{-- Backdrop --}}
                <div x-show="open" x-transition.opacity x-on:click="open = false"
                     class="fixed inset-0 z-40 bg-slate-900/40" aria-hidden="true"></div>

                {{-- Panel --}}
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="-translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="-translate-x-full"
                     class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col bg-white shadow-xl"
                     role="dialog" aria-modal="true" aria-label="Partner menu">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <span class="text-sm font-bold tracking-tight">{{ $platformName }} <span class="font-medium text-blue-600">Partner</span></span>
                        <button type="button" x-on:click="open = false"
                                class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
                                aria-label="Close menu">
                            <x-icon name="x-mark" class="h-5 w-5" />
                        </button>
                    </div>

                    {{-- Online/offline stays reachable from the drawer too,
                         not just the persistent header chip above — belt
                         and braces for the platform's most time-sensitive
                         action. --}}
                    <div class="border-b border-slate-200 px-4 py-3">
                        <livewire:provider.online-toggle />
                    </div>

                    <nav aria-label="Partner sections" class="flex-1 overflow-y-auto px-2 py-3">
                        @foreach ($navItems as $item)
                            @php $isCurrent = request()->routeIs($item['route']); @endphp
                            <a href="{{ route($item['route']) }}" wire:navigate x-on:click="open = false"
                               @if ($isCurrent) aria-current="page" @endif
                               @class([
                                   'flex min-h-12 items-center gap-3 rounded-lg px-3 text-sm font-medium transition',
                                   'bg-blue-50 text-blue-700' => $isCurrent,
                                   'text-slate-700 hover:bg-slate-50' => ! $isCurrent,
                               ])>
                                <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <form method="POST" action="{{ route('provider.logout') }}" class="border-t border-slate-200 px-4 py-3">
                        @csrf
                        <button type="submit" class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900">
                            <x-icon name="logout" class="h-5 w-5 shrink-0" />
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endauth

    <main id="provider-main" tabindex="-1" class="mx-auto w-full max-w-4xl flex-1 px-4 py-6 focus:outline-none sm:px-6">
        @auth
            {{-- Foreground job-offer alert. Purely presentational: the Alpine
                 component (resources/js/provider-alerts.js) is fed by the
                 `provider-alert-offers` event the Jobs\Index / Dashboard
                 components dispatch from their own server-authoritative
                 offer query, replaces its state on every event, and rings
                 while an offer stands. In normal flow (not fixed) so it can
                 never cover the Accept / Decline buttons below it. --}}
            <section x-data="providerOfferAlert" x-show="active" style="display: none;"
                     data-respond-url="{{ route('provider.jobs.index') }}"
                     data-on-offers-page="{{ request()->routeIs('provider.jobs.index') ? '1' : '0' }}"
                     aria-label="Incoming job offer"
                     class="mb-4 rounded-2xl border-2 border-amber-400 bg-amber-50 p-4 shadow-lg ring-4 ring-amber-200/70">
                <div class="flex items-start gap-3">
                    <span class="relative mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500 text-white" aria-hidden="true">
                        <span class="absolute inset-0 rounded-full bg-amber-400 opacity-60 motion-safe:animate-ping"></span>
                        <svg class="relative h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h2.28a1 1 0 01.95.68l1.5 4.5a1 1 0 01-.5 1.2l-2.26 1.13a11 11 0 005.52 5.52l1.13-2.26a1 1 0 011.2-.5l4.5 1.5a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.72 21 3 14.28 3 6V5z"/></svg>
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-extrabold uppercase tracking-widest text-amber-800">
                                <span role="alert">New job offer</span>
                                <span x-show="extra > 0" x-text="'+' + extra + ' more'" class="ml-1 font-semibold normal-case tracking-normal text-amber-700"></span>
                            </p>
                            <span role="timer" x-show="primary" x-text="remaining + 's left'"
                                  x-bind:class="remaining <= 10 ? 'bg-rose-600 text-white' : 'bg-amber-200 text-amber-900'"
                                  class="rounded-full px-2.5 py-0.5 text-xs font-bold tabular-nums"></span>
                        </div>

                        <template x-if="primary">
                            <div class="mt-1">
                                <p class="text-base font-bold text-slate-900">
                                    <span x-text="primary.service"></span>
                                    <span x-show="primary.price" x-text="'· ' + primary.price" class="font-semibold text-emerald-700"></span>
                                </p>
                                <p x-show="meta" x-text="meta" class="mt-0.5 text-xs text-slate-600"></p>
                            </div>
                        </template>

                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <template x-if="onOffersPage">
                                <p class="font-semibold text-amber-900">Accept or decline below ↓</p>
                            </template>
                            <template x-if="!onOffersPage">
                                <a x-bind:href="respondUrl" wire:navigate
                                   class="inline-flex min-h-10 items-center rounded-lg bg-amber-600 px-4 font-semibold text-white hover:bg-amber-700">
                                    View &amp; respond →
                                </a>
                            </template>
                            <button type="button" x-show="audioBlocked" x-on:click="enableSound($event)"
                                    class="text-xs font-semibold text-amber-800 underline underline-offset-2">
                                Tap to enable ring sound
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        @endauth

        {{ $slot }}
    </main>

    @livewireScripts

    @stack('scripts')
</body>
</html>
