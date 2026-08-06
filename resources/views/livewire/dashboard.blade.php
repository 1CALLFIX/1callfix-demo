<div>
    <h1 class="text-2xl font-bold mb-6">Dashboard</h1>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-sm text-gray-500">Bookings Today</div>
            <div class="text-2xl font-bold">{{ $stats['bookings_today'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-sm text-gray-500">Active Now</div>
            <div class="text-2xl font-bold text-amber-600">{{ $stats['active_bookings'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-sm text-gray-500">Completed Today</div>
            <div class="text-2xl font-bold text-green-600">{{ $stats['completed_today'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-sm text-gray-500">Revenue Today</div>
            <div class="text-2xl font-bold">₹{{ number_format($stats['revenue_today'], 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-sm text-gray-500">Providers Online</div>
            <div class="text-2xl font-bold">{{ $stats['providers_online'] }} <span class="text-sm text-gray-400 font-normal">/ {{ $stats['providers_total'] }}</span></div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-sm text-gray-500">Active Franchises</div>
            <div class="text-2xl font-bold">{{ $stats['franchises_active'] }}</div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm">
        <div class="px-4 py-3 border-b font-semibold">Recent Bookings</div>
        <table class="w-full text-sm">
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
                            <span @class([
                                'px-2 py-1 rounded text-xs font-medium',
                                'bg-gray-100 text-gray-700' => $booking->status === 'pending',
                                'bg-blue-100 text-blue-700' => in_array($booking->status, ['searching_provider','assigned','provider_en_route']),
                                'bg-amber-100 text-amber-700' => in_array($booking->status, ['in_progress','on_hold']),
                                'bg-green-100 text-green-700' => $booking->status === 'completed',
                                'bg-red-100 text-red-700' => in_array($booking->status, ['cancelled','disputed']),
                            ])>
                                {{ str_replace('_', ' ', $booking->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-2">₹{{ number_format($booking->price_final ?? $booking->price_quoted, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No bookings yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
