<div>
    <a href="{{ route('admin.customers.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Customers</a>

    <div class="flex items-center justify-between mt-2 mb-4">
        <h1 class="text-2xl font-bold">{{ $customer->name }}</h1>
        <x-ui.status-badge type="customer" :status="$customer->status" size="lg" />
    </div>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <x-ui.card>
            <div class="font-semibold mb-2">Details</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $customer->phone }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd>{{ $customer->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Franchise / Zone</dt><dd>{{ $customer->franchise->name ?? '—' }} / {{ $customer->zone->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Joined</dt><dd>{{ app(\App\Services\TimezoneResolver::class)->format($customer->created_at, $customer->franchise, 'd M Y, h:i A') }}</dd></div>
                <div class="flex justify-between items-center pt-2 border-t mt-2">
                    <dt class="text-gray-500">Account</dt>
                    <dd><x-ui.button size="sm" wire:click="toggleSuspended">{{ $customer->status === 'suspended' ? 'Reactivate' : 'Suspend' }}</x-ui.button></dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <div class="font-semibold mb-2">Wallet & Addresses</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Wallet balance</dt><dd class="font-mono">{{ $this->currencySymbol }}{{ number_format($this->walletBalance, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Saved addresses</dt><dd>{{ $customer->addresses->count() }}</dd></div>
            </dl>
            @if ($customer->addresses->isNotEmpty())
                <div class="mt-3 pt-3 border-t space-y-2">
                    @foreach ($customer->addresses as $addr)
                        <div class="text-xs text-gray-500">{{ $addr->label }}: {{ $addr->address_line }}, {{ $addr->city }} {{ $addr->pincode }}</div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.table>
        <x-slot:header>Recent Bookings ({{ $this->recentBookings->count() }})</x-slot:header>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Service</th><th class="px-4 py-2">Provider</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Date</th><th class="px-4 py-2"></th></tr>
        </thead>
        <tbody>
            @forelse ($this->recentBookings as $booking)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono text-xs">{{ $booking->code }}</td>
                    <td class="px-4 py-2">{{ $booking->service->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $booking->provider->user->name ?? '— unassigned —' }}</td>
                    <td class="px-4 py-2"><x-ui.status-badge type="booking" :status="$booking->status" /></td>
                    <td class="px-4 py-2 text-gray-500">{{ app(\App\Services\TimezoneResolver::class)->format($booking->created_at, $booking->franchise, 'd M Y') }}</td>
                    <td class="px-4 py-2">
                        <x-ui.button variant="ghost" :href="route('admin.bookings.show', $booking->id)">View</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-ui.empty-state icon="clipboard" title="No bookings yet" /></td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
