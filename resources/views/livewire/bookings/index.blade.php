<div>
    <h1 class="text-2xl font-bold mb-4">Bookings</h1>

    <div class="flex flex-wrap gap-2 mb-4">
        <button wire:click="$set('statusFilter', '')"
                class="px-3 py-1.5 rounded text-sm {{ $statusFilter === '' ? 'bg-slate-900 text-white' : 'bg-white border' }}">
            All
        </button>
        @foreach (['pending','searching_provider','assigned','provider_en_route','in_progress','on_hold','completed','cancelled','disputed'] as $status)
            <button wire:click="$set('statusFilter', '{{ $status }}')"
                    class="px-3 py-1.5 rounded text-sm {{ $statusFilter === $status ? 'bg-slate-900 text-white' : 'bg-white border' }}">
                {{ str_replace('_', ' ', $status) }}
                @if(isset($statusCounts[$status]))
                    <span class="opacity-60">({{ $statusCounts[$status] }})</span>
                @endif
            </button>
        @endforeach
    </div>

    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by booking code..."
           class="w-full max-w-sm border rounded px-3 py-2 text-sm mb-4">

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Service</th>
                    <th class="px-4 py-2">Provider</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Created</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bookings as $booking)
                    <tr class="border-t hover:bg-gray-50">
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
                        <td class="px-4 py-2 text-gray-500">{{ $booking->created_at->diffForHumans() }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.bookings.show', $booking->id) }}" class="text-blue-600 hover:underline">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No bookings match this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $bookings->links() }}
    </div>
</div>
