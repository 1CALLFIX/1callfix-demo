<div>
    <h1 class="mb-1 text-2xl font-bold">Hall bookings</h1>
    <p class="mb-4 text-sm text-gray-500">Every booking is paid online before the hall sees it. The owner accepts in the hall portal; one not answered in time is declined and refunded in full by itself.</p>

    <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All'] + \App\Models\HallBooking::STATUS_LABELS + ['damage' => 'Damage reported']" :active="$statusFilter" model="statusFilter" />
    <x-ui.table>
        <x-slot:footer>{{ $bookings->links() }}</x-slot:footer>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.sno-th />
                <th class="px-4 py-2">Booking</th>
                <th class="px-4 py-2">Hall</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Customer</th>
                <th class="px-4 py-2">Paid / total</th>
                <th class="px-4 py-2">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bookings as $b)
                <tr class="border-t" data-testid="hall-booking-row">
                    <x-ui.sno :rows="$bookings" :loop="$loop" />
                    <td class="px-4 py-2 font-mono text-xs">{{ $b->code }}<div class="font-sans text-gray-500">{{ $b->created_at->copy()->setTimezone($tz)->format('j M, g:i A') }}</div></td>
                    <td class="px-4 py-2">{{ $b->hall?->name }}</td>
                    <td class="px-4 py-2">{{ $b->dateLabel('j M Y') }}<div class="text-xs text-gray-500">{{ $b->slot_name }}</div></td>
                    <td class="px-4 py-2">{{ $b->customer_name }}<div class="text-xs text-gray-500">{{ $b->customer_phone }}</div></td>
                    <td class="px-4 py-2">₹{{ number_format((float) $b->paid_amount) }} / ₹{{ number_format((float) $b->total) }}
                        @if ((float) $b->refunded_amount > 0) <div class="text-xs text-red-700">refunded ₹{{ number_format((float) $b->refunded_amount) }}</div> @endif</td>
                    <td class="px-4 py-2"><x-ui.badge>{{ $b->statusLabel() }}</x-ui.badge>
                        @if ($b->status === 'awaiting_owner') <div class="mt-1 text-xs text-amber-700">answer by {{ $b->accept_by?->copy()->setTimezone($tz)->format('g:i A') }}</div> @endif
                        @if ($b->decline_reason) <div class="mt-1 text-xs text-gray-500">{{ $b->decline_reason }}</div> @endif
                        @if ($b->cancellation_reason) <div class="mt-1 text-xs text-gray-500">{{ $b->cancellation_reason }}</div> @endif
                        @if ((float) $b->deposit_amount > 0) <div class="mt-1 text-xs {{ $b->deposit_status === 'claimed' ? 'font-semibold text-red-700' : 'text-gray-500' }}">deposit ₹{{ number_format((float) $b->deposit_amount) }}: {{ ['none' => 'not paid yet', 'held' => 'held', 'returned' => 'returned', 'claimed' => 'damage reported', 'settled' => 'settled after damage check'][$b->deposit_status] ?? $b->deposit_status }}</div> @endif
                        @if ($b->damage_note) <div class="mt-1 text-xs text-red-700">"{{ $b->damage_note }}"</div> @endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">No hall bookings yet.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
