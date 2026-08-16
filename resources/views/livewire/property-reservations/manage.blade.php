<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    @if ($reservation)
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Reservations</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ $reservation->code }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $reservation->customer->name ?? 'Unknown customer' }}
                    &middot; {{ $reservation->property->name ?? '—' }}
                    @if ($reservation->property?->provider?->user) &middot; Owner: {{ $reservation->property->provider->user->name }} @endif
                </div>
            </div>
            <x-ui.badge size="lg">{{ str_replace('_', ' ', $reservation->status) }}</x-ui.badge>
        </div>

        @error('action') <div class="mb-4 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Stay</h3>
            <p class="text-sm text-gray-600">
                {{ $reservation->check_in_date->format('d M Y') }} &rarr; {{ $reservation->check_out_date->format('d M Y') }}
                &middot; {{ $reservation->number_of_nights }} night(s) &middot; {{ $reservation->number_of_guests }} guest(s)
            </p>
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Payment</h3>
            <p class="text-sm text-gray-600">
                Quoted: {{ number_format($reservation->price_quoted, 2) }}
                @if ($reservation->price_final) &middot; Final: {{ number_format($reservation->price_final, 2) }} @endif
                &middot; {{ ucfirst($reservation->payment_status) }} ({{ $reservation->payment_method ?? '—' }})
            </p>
        </x-ui.card>

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
            @if ($reservation->status === 'confirmed')
                <x-ui.button wire:click="checkInReservation">Check In</x-ui.button>
            @endif
            @if ($reservation->status === 'checked_in')
                <x-ui.button wire:click="completeReservation">Complete Stay</x-ui.button>
            @endif
            @if (! in_array($reservation->status, ['completed', 'cancelled']))
                <x-ui.button variant="danger" wire:click="cancelReservation" wire:confirm="Cancel this reservation?">Cancel</x-ui.button>
            @endif
        </div>
    @else
        <h1 class="text-2xl font-bold mb-4">Property Reservations</h1>

        <div class="flex gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search reservation code or customer..." class="border rounded px-3 py-2 text-sm w-96">
            <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach (['pending','confirmed','checked_in','completed','cancelled'] as $s)
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
                    <th class="px-4 py-2">Property</th>
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
                        <td class="px-4 py-2">{{ $r->property->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $r->check_in_date->format('d M') }} &rarr; {{ $r->check_out_date->format('d M') }}</td>
                        <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', $r->status) }}</x-ui.badge></td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewReservation({{ $r->id }})">View</x-ui.button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
