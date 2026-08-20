<div>
    <h1 class="text-2xl font-bold mb-6">Dashboard</h1>

    <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-semibold text-gray-500">Pipeline this {{ $period }}</div>
        <div class="flex gap-1 bg-white rounded p-1 shadow-sm">
            @foreach (['week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'] as $key => $label)
                <button wire:click="setPeriod('{{ $key }}')"
                        class="px-3 py-1 rounded text-xs font-medium {{ $period === $key ? 'bg-slate-900 text-white' : 'text-gray-500 hover:bg-gray-100' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-8">
        <x-ui.card class="!p-3 text-center">
            <div class="text-xs text-gray-500">Searching</div>
            <div class="text-xl font-bold text-blue-600">{{ $funnel['searching'] }}</div>
        </x-ui.card>
        <x-ui.card class="!p-3 text-center">
            <div class="text-xs text-gray-500">Assigned</div>
            <div class="text-xl font-bold text-indigo-600">{{ $funnel['assigned'] }}</div>
        </x-ui.card>
        <x-ui.card class="!p-3 text-center">
            <div class="text-xs text-gray-500">In Progress</div>
            <div class="text-xl font-bold text-amber-600">{{ $funnel['in_progress'] }}</div>
        </x-ui.card>
        <x-ui.card class="!p-3 text-center">
            <div class="text-xs text-gray-500">Completed</div>
            <div class="text-xl font-bold text-green-600">{{ $funnel['completed'] }}</div>
        </x-ui.card>
        <x-ui.card class="!p-3 text-center">
            <div class="text-xs text-gray-500">Cancelled</div>
            <div class="text-xl font-bold text-red-500">{{ $funnel['cancelled'] }}</div>
        </x-ui.card>
        <x-ui.card class="!p-3 text-center">
            <div class="text-xs text-gray-500">Disputed</div>
            <div class="text-xl font-bold text-red-700">{{ $funnel['disputed'] }}</div>
        </x-ui.card>
    </div>

    {{--
        x-ui.card is always a plain <div> (no tag/href prop) -- cards that
        should be clickable are wrapped in a real <a> here rather than
        trying to make the shared component polymorphic for one screen.
    --}}
    @php($linkOrDiv = fn ($href) => $href ? 'a' : 'div')
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <{{ $linkOrDiv($links['bookings']) }} @if($links['bookings']) href="{{ $links['bookings'] }}" @endif class="block">
            <x-ui.card class="{{ $links['bookings'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Bookings Today</div>
                <div class="text-2xl font-bold">{{ $stats['bookings_today'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings']) }}>
        <{{ $linkOrDiv($links['bookings']) }} @if($links['bookings']) href="{{ $links['bookings'] }}" @endif class="block">
            <x-ui.card class="{{ $links['bookings'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Active Now</div>
                <div class="text-2xl font-bold text-amber-600">{{ $stats['active_bookings'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings']) }}>
        <x-ui.card>
            <div class="text-sm text-gray-500">Completed Today</div>
            <div class="text-2xl font-bold text-green-600">{{ $stats['completed_today'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm text-gray-500">Revenue Today</div>
            <div class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($stats['revenue_today'], 2) }}</div>
        </x-ui.card>
        <{{ $linkOrDiv($links['providers']) }} @if($links['providers']) href="{{ $links['providers'] }}" @endif class="block">
            <x-ui.card class="{{ $links['providers'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Providers Online</div>
                <div class="text-2xl font-bold">{{ $stats['providers_online'] }} <span class="text-sm text-gray-400 font-normal">/ {{ $stats['providers_total'] }}</span></div>
            </x-ui.card>
        </{{ $linkOrDiv($links['providers']) }}>
        <{{ $linkOrDiv($links['franchises']) }} @if($links['franchises']) href="{{ $links['franchises'] }}" @endif class="block">
            <x-ui.card class="{{ $links['franchises'] ? 'hover:shadow-md transition' : '' }}">
                <div class="text-sm text-gray-500">Active Franchises</div>
                <div class="text-2xl font-bold">{{ $stats['franchises_active'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['franchises']) }}>
        {{-- Mission's own explicit "Active Operations" priority signal -- the
             current, un-time-boxed backlog, not filtered to the selected period. --}}
        <{{ $linkOrDiv($links['bookings']) }} @if($links['bookings']) href="{{ $links['bookings'] }}" @endif class="block">
            <x-ui.card class="{{ ($links['bookings'] ? 'hover:shadow-md transition ' : '').($stats['unassigned_bookings'] > 0 ? 'ring-1 ring-red-200' : '') }}">
                <div class="text-sm text-gray-500">Unassigned Bookings</div>
                <div class="text-2xl font-bold {{ $stats['unassigned_bookings'] > 0 ? 'text-red-600' : '' }}">{{ $stats['unassigned_bookings'] }}</div>
            </x-ui.card>
        </{{ $linkOrDiv($links['bookings']) }}>
    </div>

    @if (!empty($otherVerticals))
        {{-- Admin Command Center mission (Phase 4) -- Parcel/Taxi/Rental/Hotel/
             Marketplace volume, invisible on this screen before this session
             even after each vertical shipped a real admin screen. Only shows
             verticals the viewer actually holds permission to open. --}}
        <div class="text-sm font-semibold text-gray-500 mb-3">Other Verticals — Today</div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
            @foreach ($otherVerticals as $v)
                <a href="{{ $v['route'] }}" class="block">
                    <x-ui.card class="hover:shadow-md transition">
                        <div class="text-sm text-gray-500">{{ $v['label'] }}</div>
                        <div class="text-2xl font-bold">{{ $v['count'] }}</div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>
    @endif

    <x-ui.table>
        <x-slot:header>Recent Bookings</x-slot:header>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Code</th>
                <th class="px-4 py-2">Customer</th>
                <th class="px-4 py-2">Service</th>
                <th class="px-4 py-2">Provider</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Price</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recentBookings as $booking)
                <tr class="border-t">
                    <td class="px-4 py-2 font-mono text-xs">{{ $booking->code }}</td>
                    <td class="px-4 py-2">{{ $booking->customer->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $booking->service->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $booking->provider?->user?->name ?? '— unassigned —' }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="match(true) {
                            $booking->status === 'pending' => 'gray',
                            in_array($booking->status, ['searching_provider','assigned','provider_en_route']) => 'blue',
                            in_array($booking->status, ['in_progress','on_hold']) => 'amber',
                            $booking->status === 'completed' => 'green',
                            in_array($booking->status, ['cancelled','disputed']) => 'red',
                            default => 'gray',
                        }">
                            {{ str_replace('_', ' ', $booking->status) }}
                        </x-ui.badge>
                    </td>
                    <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($booking->price_final ?? $booking->price_quoted, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No bookings yet.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
