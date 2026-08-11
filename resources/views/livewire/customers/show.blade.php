<div>
    <a href="{{ route('admin.customers.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Customers</a>

    <div class="flex items-center justify-between mt-2 mb-4">
        <h1 class="text-2xl font-bold">{{ $customer->name }}</h1>
        <span @class([
            'px-3 py-1.5 rounded text-sm font-medium',
            'bg-green-100 text-green-700' => $customer->status === 'active',
            'bg-red-100 text-red-700' => $customer->status === 'suspended',
            'bg-amber-100 text-amber-700' => $customer->status === 'pending_verification',
        ])>{{ str_replace('_', ' ', $customer->status) }}</span>
    </div>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="font-semibold mb-2">Details</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $customer->phone }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd>{{ $customer->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Franchise / Zone</dt><dd>{{ $customer->franchise->name ?? '—' }} / {{ $customer->zone->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Joined</dt><dd>{{ $customer->created_at->format('d M Y, h:i A') }}</dd></div>
                <div class="flex justify-between items-center pt-2 border-t mt-2">
                    <dt class="text-gray-500">Account</dt>
                    <dd><button type="button" wire:click="toggleSuspended" class="text-xs bg-slate-900 text-white px-3 py-1.5 rounded hover:bg-slate-800">{{ $customer->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button></dd>
                </div>
            </dl>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="font-semibold mb-2">Wallet & Addresses</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Wallet balance</dt><dd class="font-mono">₹{{ number_format($this->walletBalance, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Saved addresses</dt><dd>{{ $customer->addresses->count() }}</dd></div>
            </dl>
            @if ($customer->addresses->isNotEmpty())
                <div class="mt-3 pt-3 border-t space-y-2">
                    @foreach ($customer->addresses as $addr)
                        <div class="text-xs text-gray-500">{{ $addr->label }}: {{ $addr->address_line }}, {{ $addr->city }} {{ $addr->pincode }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <div class="font-semibold p-4 pb-2">Recent Bookings ({{ $this->recentBookings->count() }})</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Service</th><th class="px-4 py-2">Provider</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Date</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse ($this->recentBookings as $booking)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $booking->code }}</td>
                        <td class="px-4 py-2">{{ $booking->service->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $booking->provider->user->name ?? '— unassigned —' }}</td>
                        <td class="px-4 py-2">{{ str_replace('_', ' ', $booking->status) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $booking->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-2"><a href="{{ route('admin.bookings.show', $booking->id) }}" class="text-blue-600 hover:underline">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No bookings yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
