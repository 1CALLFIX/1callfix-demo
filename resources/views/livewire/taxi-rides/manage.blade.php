<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    @if ($ride)
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Taxi Rides</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ $ride->code }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $ride->customer->name ?? 'Unknown customer' }}
                    @if ($ride->assignedWorker?->user) &middot; Driver: {{ $ride->assignedWorker->user->name }} @endif
                </div>
            </div>
            <x-ui.badge size="lg">{{ str_replace('_', ' ', $ride->status) }}</x-ui.badge>
        </div>

        @error('cancel') <div class="mb-4 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

        <div class="grid grid-cols-2 gap-4 mb-4">
            <x-ui.card>
                <h3 class="font-semibold mb-2">Pickup</h3>
                <p class="text-sm text-gray-600">{{ $ride->pickupAddress->address_line ?? '—' }}</p>
            </x-ui.card>
            <x-ui.card>
                <h3 class="font-semibold mb-2">Dropoff</h3>
                <p class="text-sm text-gray-600">{{ $ride->dropoffAddress->address_line ?? '—' }}</p>
            </x-ui.card>
        </div>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Fare</h3>
            <p class="text-sm text-gray-600">
                Quoted: {{ number_format($ride->price_quoted, 2) }}
                @if ($ride->price_final) &middot; Final: {{ number_format($ride->price_final, 2) }} @endif
                @if ($ride->distance_km) &middot; {{ $ride->distance_km }} km @endif
                &middot; {{ ucfirst($ride->payment_status) }} ({{ $ride->payment_method ?? '—' }})
            </p>
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Status history</h3>
            @forelse ($ride->statusHistory as $h)
                <div class="border-t first:border-t-0 py-2 text-sm">
                    <span class="font-medium">{{ str_replace('_', ' ', $h->status) }}</span>
                    <span class="text-gray-400"> — {{ $h->changed_at?->format('d M Y, h:i:s A') }}</span>
                    @if ($h->note) <div class="text-gray-500">{{ $h->note }}</div> @endif
                </div>
            @empty
                <p class="text-sm text-gray-400">No history yet.</p>
            @endforelse
        </x-ui.card>

        @if (! in_array($ride->status, ['trip_completed', 'cancelled']))
            <x-ui.button variant="danger" wire:click="cancelRide" wire:confirm="Cancel this taxi ride?">Cancel Ride</x-ui.button>
        @endif
    @else
        <x-ui.card class="mb-6">
            <h2 class="text-lg font-bold mb-3">New Taxi Ride</h2>
            @error('creation') <div class="mb-3 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

            <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Customer</label>
                    <input type="text" wire:model.live.debounce.300ms="customerSearch" placeholder="Search by name or phone..." class="border rounded px-3 py-2 text-sm w-full">
                    @if ($this->customerResults->isNotEmpty() && ! $selectedCustomerId)
                        <div class="border rounded mt-1 text-sm bg-white shadow">
                            @foreach ($this->customerResults as $c)
                                <div wire:click="selectCustomer({{ $c->id }})" class="px-3 py-1.5 hover:bg-gray-50 cursor-pointer">{{ $c->name }} ({{ $c->phone }})</div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Zone</label>
                    <select wire:model="selectedZoneId" class="border rounded px-3 py-2 text-sm w-full">
                        <option value="">Select zone...</option>
                        @foreach ($zones as $z)
                            <option value="{{ $z->id }}">{{ $z->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Pickup address</label>
                    <input type="text" wire:model="pickupAddressLine" placeholder="Address line" class="border rounded px-3 py-2 text-sm w-full mb-1">
                    <div class="flex gap-2">
                        <input type="number" step="any" wire:model="pickupLat" placeholder="Lat" class="border rounded px-3 py-2 text-sm w-1/2">
                        <input type="number" step="any" wire:model="pickupLng" placeholder="Lng" class="border rounded px-3 py-2 text-sm w-1/2">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Dropoff address (optional)</label>
                    <input type="text" wire:model="dropoffAddressLine" placeholder="Address line" class="border rounded px-3 py-2 text-sm w-full mb-1">
                    <div class="flex gap-2">
                        <input type="number" step="any" wire:model="dropoffLat" placeholder="Lat" class="border rounded px-3 py-2 text-sm w-1/2">
                        <input type="number" step="any" wire:model="dropoffLng" placeholder="Lng" class="border rounded px-3 py-2 text-sm w-1/2">
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <select wire:model="paymentMethod" class="border rounded px-3 py-2 text-sm">
                    <option value="online">Online</option>
                    <option value="wallet">Wallet</option>
                    <option value="cash">Cash</option>
                </select>
                <x-ui.button wire:click="createRide">Create Taxi Ride</x-ui.button>
            </div>
        </x-ui.card>

        <h1 class="text-2xl font-bold mb-4">Taxi Rides</h1>

        <div class="flex gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search ride code or customer..." class="border rounded px-3 py-2 text-sm w-96">
            <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach (['requested','searching_driver','assigned','driver_en_route','trip_started','trip_completed','cancelled','disputed'] as $s)
                    <option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option>
                @endforeach
            </select>
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $rides->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Driver</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Fare</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rides as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $r->code }}</td>
                        <td class="px-4 py-2">{{ $r->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $r->assignedWorker?->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', $r->status) }}</x-ui.badge></td>
                        <td class="px-4 py-2">{{ number_format($r->price_final ?? $r->price_quoted, 2) }}</td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewRide({{ $r->id }})">View</x-ui.button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
