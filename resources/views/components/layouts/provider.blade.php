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
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
        {{ $slot }}
    </main>

    @livewireScripts
    @stack('scripts')
</body>
</html>
