<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? '1CallFix Admin' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
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
                        ['label' => 'Zones', 'route' => null, 'icon' => 'map', 'active' => false],
                        ['label' => 'Franchises', 'route' => 'admin.franchises.index', 'icon' => 'building', 'active' => true],
                        ['label' => 'Services', 'route' => null, 'icon' => 'wrench', 'active' => false],
                        ['label' => 'Banners', 'route' => null, 'icon' => 'megaphone', 'active' => false],
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
</body>
</html>
