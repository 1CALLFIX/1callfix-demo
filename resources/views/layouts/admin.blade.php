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

        /* --- Sidebar nav groups -----------------------------------------
           Sidebar Reorganization session. Collapse state per group is kept
           the same way the whole-rail collapse above already works: a class
           on <body> (written by the anti-flash script at the top of <body>,
           before any nav markup paints) drives a plain CSS display:none on
           that one group's panel, rather than per-element JS toggling on
           load — so a returning visitor's collapsed groups never flash open
           before JS runs. Nine possible groups, enumerated explicitly
           rather than a generic attribute-selector trick, matching this
           file's existing preference for plain, explicit CSS over cleverness. */
        .nav-group-trigger .chevron { transition: transform .15s ease; }
        .nav-group-trigger[aria-expanded="false"] .chevron { transform: rotate(-90deg); }
        body.grp-collapsed-operations #admin-sidebar [data-group-key="operations"] .nav-group-panel,
        body.grp-collapsed-geography #admin-sidebar [data-group-key="geography"] .nav-group-panel,
        body.grp-collapsed-catalog #admin-sidebar [data-group-key="catalog"] .nav-group-panel,
        body.grp-collapsed-growth #admin-sidebar [data-group-key="growth"] .nav-group-panel,
        body.grp-collapsed-finance #admin-sidebar [data-group-key="finance"] .nav-group-panel,
        body.grp-collapsed-communication #admin-sidebar [data-group-key="communication"] .nav-group-panel,
        body.grp-collapsed-other_verticals #admin-sidebar [data-group-key="other_verticals"] .nav-group-panel,
        body.grp-collapsed-system #admin-sidebar [data-group-key="system"] .nav-group-panel {
            display: none;
        }
        /* "Other Verticals" — pre-launch verticals, deliberately muted so
           the rail reads as "intentionally secondary", not broken/missing. */
        #admin-sidebar [data-group-key="other_verticals"] { opacity: .72; }
        .nav-group-label { padding-left: .7rem; }

        /* Trix's default height is tiny; match our other textareas' rows="3"-ish feel */
        trix-editor { min-height: 8rem; }
        /* No upload endpoint wired up yet — hide the attach-files button rather
           than ship a toolbar control that silently does nothing. Services'
           existing Cover Image URL field covers the image need for now. */
        .trix-button-group--file-tools { display: none; }

        /* Admin Polish + AI session — a real, always-present sense that the
           platform is alive on every Livewire round-trip (filter change,
           button click, etc.), not just a frozen screen until the response
           lands. `.delay` on the wire:loading below already keeps this from
           flashing on sub-200ms requests; the indeterminate sweep animation
           itself is pure CSS, no JS/chart-library dependency. */
        @keyframes admin-loading-sweep { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
        #admin-loading-bar-fill { animation: admin-loading-sweep 1.1s ease-in-out infinite; }

        /* Skip-to-content link — visually hidden until focused (keyboard
           users tabbing from the very top of the page can jump straight
           past the sidebar into the actual screen content). */
        .skip-link { position: absolute; left: -9999px; top: 0; z-index: 50; }
        .skip-link:focus { left: 0.5rem; top: 0.5rem; }
    </style>
</head>
@php
    // Sidebar Reorganization session — grouped nav, replacing the previous
    // ~40-item flat rail. Computed here (before <body>, not inside <nav>
    // below) purely so <body>'s data-active-nav-group attribute — read by
    // the anti-flash script above the fold — can know the answer before
    // the nav itself renders. Each item's 'permission' is still the SAME
    // slug (or list of slugs, any-of) its target screen's own mount()
    // enforces — kept in sync by hand since no shared route->permission
    // registry exists in this codebase (Phase 11 audit finding). This only
    // hides links a user can't open anyway — it is NOT the enforcement
    // boundary, each screen's own mount() check still is.
    $navGroups = [];
    $activeNavGroup = null;

    if (auth()->check()) {
        $navGroups = [
            'overview' => [
                'label' => 'Overview', 'collapsible' => false, 'icon' => null,
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],
                ],
            ],
            'operations' => [
                'label' => 'Operations', 'collapsible' => true, 'icon' => 'clipboard',
                'items' => [
                    ['label' => 'Bookings', 'route' => 'admin.bookings.index', 'icon' => 'clipboard', 'permission' => 'bookings.view'],
                    ['label' => 'Customers', 'route' => 'admin.customers.index', 'icon' => 'users', 'permission' => 'customers.view'],
                    ['label' => 'Providers', 'route' => 'admin.providers.index', 'icon' => 'users', 'permission' => 'providers.view'],
                    ['label' => 'Workers', 'route' => 'admin.workers.index', 'icon' => 'users', 'permission' => 'workers.view'],
                    ['label' => 'KYC Support Requests', 'route' => 'admin.kyc.support-requests.index', 'icon' => 'shield', 'permission' => ['kyc.support_requests.create', 'kyc.support_requests.decide']],
                    ['label' => 'Chat', 'route' => 'admin.chat.index', 'icon' => 'chat', 'permission' => 'chat.view'],
                ],
            ],
            'geography' => [
                'label' => 'Geography', 'collapsible' => true, 'icon' => 'map',
                'items' => [
                    ['label' => 'Zones', 'route' => 'admin.zones.index', 'icon' => 'map', 'permission' => 'zones.manage'],
                    ['label' => 'Geography', 'route' => 'admin.geography.index', 'icon' => 'map', 'permission' => 'geography.manage'],
                    ['label' => 'Franchises', 'route' => 'admin.franchises.index', 'icon' => 'building', 'permission' => 'franchises.manage'],
                    ['label' => 'Modules', 'route' => 'admin.modules.index', 'icon' => 'gear', 'permission' => 'modules.manage'],
                ],
            ],
            'catalog' => [
                'label' => 'Catalog', 'collapsible' => true, 'icon' => 'wrench',
                'items' => [
                    ['label' => 'Services', 'route' => 'admin.services.index', 'icon' => 'wrench', 'permission' => 'services.manage'],
                    ['label' => 'Categories', 'route' => 'admin.categories.index', 'icon' => 'wrench', 'permission' => 'categories.manage'],
                    ['label' => 'Subcategories', 'route' => 'admin.subcategories.index', 'icon' => 'wrench', 'permission' => 'categories.manage'],
                ],
            ],
            'growth' => [
                'label' => 'Growth', 'collapsible' => true, 'icon' => 'sparkles',
                'items' => [
                    ['label' => 'Banners', 'route' => 'admin.banners.index', 'icon' => 'megaphone', 'permission' => 'banners.manage'],
                    ['label' => 'Badges', 'route' => 'admin.badges.index', 'icon' => 'tag', 'permission' => 'badges.view'],
                    ['label' => 'Flash Sales', 'route' => 'admin.flash-sales.index', 'icon' => 'bolt', 'permission' => 'flash_sales.view'],
                    ['label' => 'Performance Campaigns', 'route' => 'admin.performance-campaigns.index', 'icon' => 'trophy', 'permission' => 'performance_campaigns.view'],
                    ['label' => 'Loyalty & Referrals', 'route' => 'admin.loyalty.index', 'icon' => 'sparkles', 'permission' => 'loyalty.view'],
                    ['label' => 'Plans & Memberships', 'route' => 'admin.plans.index', 'icon' => 'sparkles', 'permission' => 'plans.view'],
                    ['label' => 'Subscriptions', 'route' => 'admin.subscriptions.index', 'icon' => 'ticket', 'permission' => 'subscriptions.view'],
                ],
            ],
            'finance' => [
                'label' => 'Finance', 'collapsible' => true, 'icon' => 'banknotes',
                'items' => [
                    ['label' => 'Payouts', 'route' => 'admin.payouts.index', 'icon' => 'banknotes', 'permission' => 'payouts.manage'],
                    ['label' => 'Wallet Ledger', 'route' => 'admin.wallet-ledger.index', 'icon' => 'banknotes', 'permission' => 'wallets.view'],
                    ['label' => 'Commissions', 'route' => 'admin.commissions.index', 'icon' => 'banknotes', 'permission' => 'commissions.view'],
                    ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'banknotes', 'permission' => 'payments.view'],
                ],
            ],
            'communication' => [
                'label' => 'Communication', 'collapsible' => true, 'icon' => 'chat',
                'items' => [
                    ['label' => 'Notification Center', 'route' => 'admin.notifications.index', 'icon' => 'bell', 'permission' => 'notification.view'],
                    ['label' => 'Website / CMS', 'route' => 'admin.cms.index', 'icon' => 'document', 'permission' => 'cms.manage'],
                ],
            ],
            // Pre-launch verticals — none is activated yet (see Modules).
            // Collapsed by default (see the anti-flash script above) and
            // visually muted (see .nav-group opacity rule) so this doesn't
            // compete for attention with the one vertical that's actually
            // live, without hiding it outright.
            'other_verticals' => [
                'label' => 'Other Verticals', 'collapsible' => true, 'icon' => 'building', 'preLaunch' => true,
                'items' => [
                    ['label' => 'Parcel Orders', 'route' => 'admin.parcel-orders.index', 'icon' => 'clipboard', 'permission' => 'parcel_orders.view'],
                    ['label' => 'Taxi Rides', 'route' => 'admin.taxi-rides.index', 'icon' => 'clipboard', 'permission' => 'taxi_rides.view'],
                    ['label' => 'Properties', 'route' => 'admin.properties.index', 'icon' => 'building', 'permission' => 'properties.manage'],
                    ['label' => 'Property Reservations', 'route' => 'admin.property-reservations.index', 'icon' => 'clipboard', 'permission' => 'property_reservations.view'],
                    ['label' => 'Vehicles', 'route' => 'admin.vehicles.index', 'icon' => 'building', 'permission' => 'vehicles.manage'],
                    ['label' => 'Equipment', 'route' => 'admin.equipment.index', 'icon' => 'wrench', 'permission' => 'equipment.manage'],
                    ['label' => 'Rental Reservations', 'route' => 'admin.rental-reservations.index', 'icon' => 'clipboard', 'permission' => 'rental_reservations.view'],
                    ['label' => 'Accommodations', 'route' => 'admin.accommodations.index', 'icon' => 'building', 'permission' => 'accommodations.manage'],
                    ['label' => 'Hotel Reservations', 'route' => 'admin.hotel-reservations.index', 'icon' => 'clipboard', 'permission' => 'hotel_reservations.view'],
                    ['label' => 'Stores', 'route' => 'admin.stores.index', 'icon' => 'building', 'permission' => 'stores.manage'],
                    ['label' => 'Marketplace Categories', 'route' => 'admin.marketplace-categories.index', 'icon' => 'wrench', 'permission' => 'marketplace_categories.manage'],
                    ['label' => 'Products', 'route' => 'admin.products.index', 'icon' => 'wrench', 'permission' => 'products.manage'],
                    ['label' => 'Add-Ons', 'route' => 'admin.add-ons.index', 'icon' => 'wrench', 'permission' => 'products.manage'],
                    ['label' => 'Marketplace Orders', 'route' => 'admin.marketplace-orders.index', 'icon' => 'clipboard', 'permission' => 'marketplace_orders.view'],
                ],
            ],
            'system' => [
                'label' => 'System', 'collapsible' => true, 'icon' => 'gear',
                'items' => [
                    ['label' => 'Operations', 'route' => 'admin.operations.index', 'icon' => 'activity', 'permission' => 'operations.view'],
                    ['label' => 'Roles & Permissions', 'route' => 'admin.roles.index', 'icon' => 'shield', 'permission' => 'roles.manage'],
                    ['label' => 'Settings', 'route' => 'admin.settings.index', 'icon' => 'gear', 'permission' => 'settings.manage'],
                ],
            ],
        ];

        $canSeeNavItem = fn ($item) => collect((array) $item['permission'])
            ->contains(fn ($p) => auth()->user()->hasPermissionAnywhere($p));

        foreach ($navGroups as $key => $group) {
            $items = array_values(array_filter($group['items'], $canSeeNavItem));
            if (empty($items)) {
                unset($navGroups[$key]);
                continue;
            }
            $navGroups[$key]['items'] = $items;

            if (collect($items)->contains(fn ($item) => request()->routeIs($item['route'].'*'))) {
                $activeNavGroup = $key;
            }
        }
    }
@endphp
<body class="bg-gray-100 text-gray-900" data-active-nav-group="{{ $activeNavGroup ?? '' }}">
    {{-- Restore the sidebar state before the first paint, so a page load
         doesn't flash the collapsed rail open. Also syncs the toggle
         button's aria-expanded to match (was previously hardcoded "false"
         in the markup below regardless of the restored state). --}}
    <script>
        if (localStorage.getItem('adminSidebarExpanded') === '1') {
            document.body.classList.add('sidebar-expanded');
        }

        // Sidebar Reorganization session — same before-first-paint idea,
        // per nav group. The group containing the current page is never
        // force-collapsed here (see data-active-nav-group above) even if
        // it was collapsed last time — a user landing on a deep link
        // inside some group should never wonder where they are in a
        // collapsed sidebar. Every OTHER group follows its stored
        // preference, or the built-in default (every group open except
        // "Other Verticals", which starts collapsed until a viewer
        // deliberately opens it).
        (function () {
            var GROUP_KEYS = ['operations', 'geography', 'catalog', 'growth', 'finance', 'communication', 'other_verticals', 'system'];
            var activeGroup = document.body.getAttribute('data-active-nav-group');
            var stored = {};
            try { stored = JSON.parse(localStorage.getItem('adminSidebarGroupCollapse') || '{}'); } catch (e) { stored = {}; }

            GROUP_KEYS.forEach(function (key) {
                if (key === activeGroup) return;
                var collapsed = Object.prototype.hasOwnProperty.call(stored, key) ? !!stored[key] : (key === 'other_verticals');
                if (collapsed) document.body.classList.add('grp-collapsed-' + key);
            });
        })();
    </script>

    @auth
        <a href="#admin-main-content" class="skip-link bg-white text-slate-900 text-sm font-medium px-3 py-2 rounded shadow">Skip to content</a>

        {{-- Global Livewire activity indicator — one instance for the whole
             panel rather than each screen building its own, so every
             Livewire component (filters, forms, actions) gets it for free. --}}
        <div wire:loading.delay.flex class="fixed top-0 left-0 right-0 z-50 h-0.5 bg-slate-200 overflow-hidden" role="status" aria-label="Loading">
            <div id="admin-loading-bar-fill" class="h-full w-1/3 bg-indigo-500"></div>
        </div>

        <header class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button type="button" id="sidebar-toggle"
                        class="w-9 h-9 rounded flex items-center justify-center hover:bg-slate-700 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                        title="Toggle menu" aria-label="Toggle menu" aria-expanded="false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <div class="font-bold text-lg">{{ \App\Models\Setting::get('branding.platform_name', '1CallFix Admin') }}</div>
                <span class="text-xs bg-slate-700 px-2 py-0.5 rounded">{{ \App\Models\Setting::get('branding.operating_city_label', 'Nellore') }}</span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <livewire:global-search />
                <span class="text-gray-300">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-red-300 hover:text-red-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-300 rounded">Logout</button>
                </form>
            </div>
        </header>

        <div class="flex">
            <nav id="admin-sidebar" aria-label="Main navigation" class="bg-slate-800 min-h-[calc(100vh-52px)] flex flex-col items-center py-4 gap-1 sticky top-[52px] shrink-0 overflow-y-auto">
                @foreach ($navGroups as $groupKey => $group)
                    <div class="w-full" data-group-key="{{ $groupKey }}">
                        @if ($group['collapsible'])
                            @php $groupExpanded = $groupKey === $activeNavGroup || $groupKey !== 'other_verticals'; @endphp
                            <button type="button"
                                    class="nav-group-trigger nav-item h-9 rounded-lg flex items-center gap-3 w-full text-gray-500 hover:bg-slate-700 hover:text-gray-300 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                    aria-expanded="{{ $groupExpanded ? 'true' : 'false' }}"
                                    aria-controls="nav-group-panel-{{ $groupKey }}"
                                    title="{{ $group['label'] }}{{ !empty($group['preLaunch']) ? ' (pre-launch)' : '' }}">
                                <span class="shrink-0"><x-icon :name="$group['icon']" /></span>
                                <span class="nav-label nav-group-label text-[11px] font-semibold uppercase tracking-wide flex-1 text-left flex items-center gap-1.5">
                                    {{ $group['label'] }}
                                    @if (!empty($group['preLaunch']))
                                        <span class="normal-case font-normal text-amber-400/90">· pre-launch</span>
                                    @endif
                                </span>
                                <span class="nav-label shrink-0"><x-icon name="chevron-down" class="chevron w-3.5 h-3.5" /></span>
                            </button>
                        @else
                            <div class="nav-group-label text-[11px] font-semibold uppercase tracking-wide text-gray-500 nav-label px-1 mb-1">{{ $group['label'] }}</div>
                        @endif

                        <div id="nav-group-panel-{{ $groupKey }}" class="nav-group-panel flex flex-col items-center gap-1 w-full" role="group" aria-label="{{ $group['label'] }}">
                            @foreach ($group['items'] as $item)
                                @php $isCurrent = request()->routeIs($item['route'].'*'); @endphp
                                <a href="{{ route($item['route']) }}"
                                   title="{{ $item['label'] }}"
                                   @if ($isCurrent) aria-current="page" @endif
                                   @class([
                                       'nav-item h-11 rounded-lg flex items-center gap-3 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white',
                                       'bg-slate-600 text-white' => $isCurrent,
                                       'text-gray-400 hover:bg-slate-700 hover:text-white' => !$isCurrent,
                                   ])>
                                    <span class="shrink-0"><x-icon :name="$item['icon']" /></span>
                                    <span class="nav-label text-sm">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>

            <main id="admin-main-content" class="flex-1 p-6 max-w-6xl" tabindex="-1">
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

    {{-- Sidebar Reorganization session — collapsible nav-group triggers.
         Plain JS + localStorage, same as the whole-rail toggle just above
         (no Alpine.js dependency exists anywhere in this codebase today —
         checked first; the modal component's own docblock explicitly
         chose to stay dependency-free rather than add one). Each trigger
         is a real <button> with aria-expanded, so Tab/Enter/Space already
         work with zero extra wiring. --}}
    <script>
        (function () {
            var STORAGE_KEY = 'adminSidebarGroupCollapse';

            var readStored = function () {
                try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { return {}; }
            };

            document.querySelectorAll('.nav-group-trigger').forEach(function (trigger) {
                var wrapper = trigger.closest('[data-group-key]');
                var key = wrapper ? wrapper.getAttribute('data-group-key') : null;
                if (! key) return;

                // The server rendered aria-expanded from its own "sensible
                // default" (progressive-enhancement fallback for no-JS);
                // the anti-flash script at the top of <body> already
                // decided the REAL visible state from localStorage before
                // this ran. Sync the two now so a screen reader is never
                // told "expanded" for a panel that's actually display:none
                // (or vice-versa) — this can't itself cause a visual flash,
                // aria attributes don't paint.
                var collapsed = document.body.classList.contains('grp-collapsed-' + key);
                trigger.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

                trigger.addEventListener('click', function () {
                    var willExpand = trigger.getAttribute('aria-expanded') !== 'true';
                    trigger.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
                    document.body.classList.toggle('grp-collapsed-' + key, ! willExpand);

                    var stored = readStored();
                    stored[key] = ! willExpand; // store COLLAPSED, not expanded
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
                });
            });
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
