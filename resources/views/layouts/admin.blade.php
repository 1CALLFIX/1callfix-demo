<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? '1CallFix Admin' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
    {{-- Trix — WYSIWYG editor for Services' description field. CDN, no build
         step, same convention as Tailwind above. Inert on any page without a
         <trix-editor> element, so it's fine to load globally rather than gate
         it per-route. --}}
    <link rel="stylesheet" href="https://unpkg.com/trix@2.1.6/dist/trix.css">
    <style>
        /* Trix's default height is tiny; match our other textareas' rows="3"-ish feel */
        trix-editor { min-height: 8rem; }
        /* No upload endpoint wired up yet — hide the attach-files button rather
           than ship a toolbar control that silently does nothing. Services'
           existing Cover Image URL field covers the image need for now. */
        .trix-button-group--file-tools { display: none; }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">
    @auth
        <header class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <div class="font-bold text-lg">1CallFix Admin</div>
                <span class="text-xs bg-slate-700 px-2 py-0.5 rounded">Nellore</span>
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
            <nav class="bg-slate-800 w-16 min-h-[calc(100vh-52px)] flex flex-col items-center py-4 gap-1 sticky top-[52px]">
                @php
                    $navItems = [
                        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'home', 'active' => true],
                        ['label' => 'Bookings', 'route' => 'admin.bookings.index', 'icon' => 'clipboard', 'active' => true],
                        ['label' => 'Providers', 'route' => 'admin.providers.index', 'icon' => 'users', 'active' => true],
                        ['label' => 'Zones', 'route' => 'admin.zones.index', 'icon' => 'map', 'active' => true],
                        ['label' => 'Franchises', 'route' => 'admin.franchises.index', 'icon' => 'building', 'active' => true],
                        ['label' => 'Services', 'route' => 'admin.services.index', 'icon' => 'wrench', 'active' => true],
                        ['label' => 'Banners', 'route' => 'admin.banners.index', 'icon' => 'megaphone', 'active' => true],
                        ['label' => 'Settings', 'route' => null, 'icon' => 'gear', 'active' => false],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @php $isCurrent = $item['route'] && request()->routeIs($item['route'].'*'); @endphp
                    <a href="{{ $item['active'] ? route($item['route']) : '#' }}"
                       title="{{ $item['label'] }}{{ $item['active'] ? '' : ' (coming soon)' }}"
                       @class([
                           'w-11 h-11 rounded-lg flex items-center justify-center transition',
                           'bg-slate-600 text-white' => $isCurrent,
                           'text-gray-400 hover:bg-slate-700 hover:text-white' => !$isCurrent && $item['active'],
                           'text-gray-600 cursor-not-allowed' => !$item['active'],
                       ])>
                        @include('components.icon', ['name' => $item['icon']])
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
    @endif
</body>
</html>
