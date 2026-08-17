<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    @if ($reservation)
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Rental Reservations</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ $reservation->code }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $reservation->customer->name ?? 'Unknown customer' }}
                    &middot; {{ ucfirst($reservation->rental_type) }}: {{ $reservation->rentable?->name ?? trim(($reservation->rentable?->make ?? '').' '.($reservation->rentable?->model ?? '')) ?: '—' }}
                    @if ($reservation->rentable?->provider?->user) &middot; Owner: {{ $reservation->rentable->provider->user->name }} @endif
                    @if ($reservation->rental_mode) &middot; {{ str_replace('_', ' ', $reservation->rental_mode) }} @endif
                    @if ($reservation->driver?->user) &middot; Driver: {{ $reservation->driver->user->name }} @endif
                </div>
            </div>
            <x-ui.badge size="lg">{{ str_replace('_', ' ', $reservation->status) }}</x-ui.badge>
        </div>

        @error('action') <div class="mb-4 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Rental period</h3>
            <p class="text-sm text-gray-600">
                {{ $reservation->starts_at->format('d M Y, h:i A') }} &rarr; {{ $reservation->ends_at->format('d M Y, h:i A') }}
                &middot; {{ $reservation->duration_quantity }} {{ $reservation->duration_unit }} unit(s)
            </p>
            @if ($reservation->special_requests)
                <p class="text-sm text-gray-500 mt-1">{{ $reservation->special_requests }}</p>
            @endif
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Payment &amp; deposit</h3>
            <p class="text-sm text-gray-600">
                Quoted: {{ number_format($reservation->price_quoted, 2) }}
                @if ($reservation->price_final) &middot; Final: {{ number_format($reservation->price_final, 2) }} @endif
                &middot; {{ ucfirst($reservation->payment_status) }} ({{ $reservation->payment_method ?? '—' }})
            </p>
            @if ($reservation->deposit_amount)
                <p class="text-sm text-gray-500 mt-1">Deposit: {{ number_format($reservation->deposit_amount, 2) }} &middot; {{ str_replace('_', ' ', $reservation->deposit_status) }}</p>
            @endif
        </x-ui.card>

        @if ($reservation->rental_type === 'equipment')
            <x-ui.card class="mb-4">
                <h3 class="font-semibold mb-2">Inspection</h3>
                @if ($reservation->inspected_at)
                    <p class="text-sm text-gray-600">Inspected {{ $reservation->inspected_at->format('d M Y, h:i A') }}</p>
                    @if ($reservation->inspection_notes)
                        <p class="text-sm text-gray-500 mt-1">{{ $reservation->inspection_notes }}</p>
                    @endif
                @elseif ($reservation->status === 'returned')
                    <textarea wire:model="inspectionNotes" placeholder="Inspection notes (optional)" class="border rounded px-3 py-2 text-sm w-full mb-2"></textarea>
                    <x-ui.button wire:click="inspectReservation">Record Inspection</x-ui.button>
                @else
                    <p class="text-sm text-gray-400">Not yet returned.</p>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Status history</h3>
            @forelse ($reservation->statusHistory as $h)
                <div class="border-t first:border-t-0 py-2 text-sm">
                    <span class="font-medium">{{ str_replace('_', ' ', $h->status) }}</span>
                    <span class="text-gray-400"> — {{ $h->changed_at?->format('d M Y, h:i:s A') }}</span>
                    @if ($h->note) <div class="text-gray-500">{{ $h->note }}</div> @endif
                </div>
            @empty
                <p class="text-sm text-gray-400">No history yet.</p>
            @endforelse
        </x-ui.card>

        <div class="flex gap-2">
            @if ($reservation->status === 'pending')
                <x-ui.button wire:click="confirmReservation">Confirm</x-ui.button>
            @endif
            @if ($reservation->status === 'confirmed' && $reservation->rental_mode !== 'with_driver')
                <x-ui.button wire:click="pickupReservation">Record Pickup</x-ui.button>
            @endif
            @if (($reservation->status === 'picked_up') || ($reservation->status === 'confirmed' && $reservation->rental_mode === 'with_driver'))
                <x-ui.button wire:click="startReservation">Start Rental</x-ui.button>
            @endif
            @if ($reservation->status === 'active' && $reservation->rental_mode !== 'with_driver')
                <x-ui.button wire:click="returnReservation">Record Return</x-ui.button>
            @endif
            @if (($reservation->status === 'returned') || ($reservation->status === 'active' && $reservation->rental_mode === 'with_driver'))
                <x-ui.button wire:click="completeReservation">Complete</x-ui.button>
            @endif
            @if (! in_array($reservation->status, ['completed', 'cancelled']))
                <x-ui.button variant="danger" wire:click="cancelReservation" wire:confirm="Cancel this reservation?">Cancel</x-ui.button>
            @endif
        </div>
    @else
        <h1 class="text-2xl font-bold mb-4">Rental Reservations</h1>

        <div class="flex gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search reservation code or customer..." class="border rounded px-3 py-2 text-sm w-96">
            <select wire:model.live="typeFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All types</option>
                <option value="vehicle">Vehicle</option>
                <option value="equipment">Equipment</option>
            </select>
            <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach (['pending','confirmed','picked_up','active','returned','completed','cancelled'] as $s)
                    <option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option>
                @endforeach
            </select>
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $reservations->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Type</th>
                    <th class="px-4 py-2">Item</th>
                    <th class="px-4 py-2">Dates</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reservations as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $r->code }}</td>
                        <td class="px-4 py-2">{{ $r->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ ucfirst($r->rental_type) }}{{ $r->rental_mode ? ' / '.str_replace('_', ' ', $r->rental_mode) : '' }}</td>
                        <td class="px-4 py-2">{{ $r->rentable?->name ?? trim(($r->rentable?->make ?? '').' '.($r->rentable?->model ?? '')) ?: '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $r->starts_at->format('d M') }} &rarr; {{ $r->ends_at->format('d M') }}</td>
                        <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', $r->status) }}</x-ui.badge></td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewReservation({{ $r->id }})">View</x-ui.button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
