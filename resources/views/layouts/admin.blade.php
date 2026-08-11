<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Models\Setting::get('branding.platform_name', '1CallFix Admin') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
    {{-- Trix — WYSIWYG editor for Services' description field. CDN, no build
         step, same convention as Tailwind above. Inert on any page without a
         <trix-editor> element, so it's fine to load globally rather than gate
         it per-route. --}}
    <link rel="stylesheet" href="https://unpkg.com/trix@2.1.6/dist/trix.css">
    <style>
        /* --- Collapsible sidebar ---------------------------------------
           Width and label visibility are driven by a class on <body> rather
           than per-element JS, so one toggle flips the whole rail. The state
           is written to <body> by an inline script in <head> (below) before
           first paint — set it here and the rail would visibly jump from
           collapsed to expanded on every page load. */
        #admin-sidebar { width: 4rem; transition: width .15s ease; }
        body.sidebar-expanded #admin-sidebar { width: 14rem; }
        #admin-sidebar .nav-label { display: none; white-space: nowrap; }
        body.sidebar-expanded #admin-sidebar .nav-label { display: inline; }
        #admin-sidebar .nav-item { width: 2.75rem; justify-content: center; }
        body.sidebar-expanded #admin-sidebar .nav-item {
            width: calc(100% - 1rem);
            justify-content: flex-start;
            padding-left: .7rem;
        }

        /* Trix's default height is tiny; match our other textareas' rows="3"-ish feel */
        trix-editor { min-height: 8rem; }
        /* No upload endpoint wired up yet — hide the attach-files button rather
           than ship a toolbar control that silently does nothing. Services'
           existing Cover Image URL field covers the image need for now. */
        .trix-button-group--file-tools { display: none; }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">
    {{-- Restore the sidebar state before the first paint, so a page load
         doesn't flash the collapsed rail open. --}}
    <script>
        if (localStorage.getItem('adminSidebarExpanded') === '1') {
            document.body.classList.add('sidebar-expanded');
        }
    </script>

    @auth
        <header class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button type="button" id="sidebar-toggle"
                        class="w-9 h-9 rounded flex items-center justify-center hover:bg-slate-700 transition"
                        title="Toggle menu" aria-label="Toggle menu" aria-expanded="false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <div class="font-bold text-lg">{{ \App\Models\Setting::get('branding.platform_name', '1CallFix Admin') }}</div>
                <span class="text-xs bg-slate-700 px-2 py-0.5 rounded">{{ \App\Models\Setting::get('branding.operating_city_label', 'Nellore') }}</span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-300">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-red-300 hover:text-red-100">Logout</button>
                </form>
            </div>
        </header>

        <div class="flex">
            <nav id="admin-sidebar" class="bg-slate-800 min-h-[calc(100vh-52px)] flex flex-col items-center py-4 gap-1 sticky top-[52px] shrink-0">
                @php
                    $navItems = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'home', 'active' => true],
                        ['label' => 'Bookings', 'route' => 'admin.bookings.index', 'icon' => 'clipboard', 'active' => true],
                        ['label' => 'Providers', 'route' => 'admin.providers.index', 'icon' => 'users', 'active' => true],
                        ['label' => 'Zones', 'route' => 'admin.zones.index', 'icon' => 'map', 'active' => true],
                        ['label' => 'Franchises', 'route' => 'admin.franchises.index', 'icon' => 'building', 'active' => true],
                        ['label' => 'Services', 'route' => 'admin.services.index', 'icon' => 'wrench', 'active' => true],
                        ['label' => 'Banners', 'route' => 'admin.banners.index', 'icon' => 'megaphone', 'active' => true],
                        ['label' => 'Website / CMS', 'route' => 'admin.cms.index', 'icon' => 'document', 'active' => true],
                        ['label' => 'Payouts', 'route' => 'admin.payouts.index', 'icon' => 'banknotes', 'active' => true],
                        ['label' => 'Notification Center', 'route' => 'admin.notifications.index', 'icon' => 'bell', 'active' => true],
                        ['label' => 'Plans & Memberships', 'route' => 'admin.plans.index', 'icon' => 'sparkles', 'active' => true],
                        ['label' => 'Subscriptions', 'route' => 'admin.subscriptions.index', 'icon' => 'ticket', 'active' => true],
                        ['label' => 'Roles & Permissions', 'route' => 'admin.roles.index', 'icon' => 'shield', 'active' => true],
                        ['label' => 'Settings', 'route' => 'admin.settings.index', 'icon' => 'gear', 'active' => true],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @php $isCurrent = $item['route'] && request()->routeIs($item['route'].'*'); @endphp
                    <a href="{{ $item['active'] ? route($item['route']) : '#' }}"
                       title="{{ $item['label'] }}{{ $item['active'] ? '' : ' (coming soon)' }}"
                       @class([
                           'nav-item h-11 rounded-lg flex items-center gap-3 transition',
                           'bg-slate-600 text-white' => $isCurrent,
                           'text-gray-400 hover:bg-slate-700 hover:text-white' => !$isCurrent && $item['active'],
                           'text-gray-600 cursor-not-allowed' => !$item['active'],
                       ])>
                        <span class="shrink-0">@include('components.icon', ['name' => $item['icon']])</span>
                        <span class="nav-label text-sm">
                            {{ $item['label'] }}
                            @unless ($item['active'])
                                <span class="text-xs text-gray-500">(soon)</span>
                            @endunless
                        </span>
                    </a>
                @endforeach
            </nav>

            <main class="flex-1 p-6 max-w-6xl">
                {{ $slot }}
            </main>
        </div>
    @else
        <main>{{ $slot }}</main>
    @endauth

    <script>
        (function () {
            const toggle = document.getElementById('sidebar-toggle');
            if (! toggle) return; // logged-out layout has no sidebar

            const sync = () => toggle.setAttribute('aria-expanded',
                document.body.classList.contains('sidebar-expanded') ? 'true' : 'false');

            toggle.addEventListener('click', () => {
                const expanded = document.body.classList.toggle('sidebar-expanded');
                localStorage.setItem('adminSidebarExpanded', expanded ? '1' : '0');
                sync();
                // The zone map sizes itself to its container; widening the rail
                // shrinks that container, and Google Maps won't notice on its
                // own. Harmless no-op on every other screen.
                window.dispatchEvent(new Event('resize'));
            });

            sync();
        })();
    </script>

    @livewireScripts

    <script src="https://unpkg.com/trix@2.1.6/dist/trix.umd.min.js"></script>
    <script>
        // Belt-and-braces alongside the CSS hide above: block drag-and-drop
        // file attachments too, since there's still no upload endpoint.
        document.addEventListener('trix-file-accept', (e) => e.preventDefault());
    </script>

    @if (config('services.google_maps.key'))
        {{-- No `callback=` param on purpose: it requires window.initZoneMap to
             already exist the instant this script finishes loading, which races
             against our own init and throws "initZoneMap is not a function"
             whenever the Maps script wins that race. public/js/zone-map.js
             polls for `window.google.maps` instead.
             No `libraries=drawing` either — DrawingManager was removed by Google
             as of Maps JS API v3.65; zone-map.js draws boundaries manually. --}}
        <script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.key') }}" async defer></script>
        {{-- Deliberately a plain static file, not inline Livewire @script content —
             see the comment at the top of zone-map.js for why. --}}
        <script src="{{ asset('js/zone-map.js') }}" defer></script>
        {{-- Single-marker picker for the New Booking modal's address form —
             see the comment at the top of booking-address-map.js. --}}
        <script src="{{ asset('js/booking-address-map.js') }}" defer></script>
    @endif
</body>
</html>
