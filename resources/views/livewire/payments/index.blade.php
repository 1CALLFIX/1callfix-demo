<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold">Payments</h1>
        @if ($methodFilter !== 'cash')
            <x-ui.button variant="secondary" size="sm" wire:click="exportPaymentsCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
        @endif
    </div>
    <div class="text-xs text-gray-400 mb-4">Gateway: {{ $gatewayDisplayName }}</div>

    <x-ui.archive-bars :bars="$archiveBars" />

    <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All', 'online' => 'Online', 'wallet' => 'Wallet', 'cash' => 'Cash']"
                      :active="$methodFilter" model="methodFilter" />

    <x-ui.filter-tabs class="mb-3"
                      :tabs="$methodFilter === 'cash'
                          ? ['' => 'All', 'pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled']
                          : ['' => 'All', 'pending' => 'Pending', 'captured' => 'Captured', 'failed' => 'Failed', 'refunded' => 'Refunded', 'archived' => 'Archived']"
                      :active="$statusFilter" model="statusFilter" />

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ $methodFilter === 'cash' ? 'Search booking code, name or phone...' : 'Search order/payment id, booking code, name or phone...' }}" class="border rounded px-3 py-2 text-sm w-80">
        @if ($methodFilter !== 'cash')
            <select wire:model.live="purposeFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All purposes</option>
                <option value="booking">Booking</option>
                <option value="wallet_topup">Wallet top-up</option>
                <option value="plan_subscription">Plan subscription</option>
                <option value="parcel_order">Parcel order</option>
                <option value="taxi_ride">Taxi ride</option>
                <option value="property_reservation">Property reservation</option>
                <option value="marketplace_order">Marketplace order</option>
            </select>
        @endif
    </div>

    @if ($methodFilter === 'cash')
        <p class="text-xs text-gray-500 mb-3">Cash is collected by the provider after the job, so there is no payment record. These are the bookings paid in cash.</p>
        @if (! $cashAllowed)
            <p class="text-sm text-gray-500">You do not have permission to view bookings.</p>
        @else
            <x-ui.table>
                <x-slot:footer>{{ $cashBookings->links() }}</x-slot:footer>
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <x-ui.sno-th />
                        <th class="px-4 py-2">Booking</th>
                        <th class="px-4 py-2">Customer</th>
                        <th class="px-4 py-2">Service</th>
                        <th class="px-4 py-2">Amount</th>
                        <th class="px-4 py-2">Booking status</th>
                        <th class="px-4 py-2">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cashBookings as $b)
                        <tr class="border-t hover:bg-gray-50">
                            <x-ui.sno :rows="$cashBookings" :loop="$loop" />
                            <td class="px-4 py-2"><a href="{{ route('admin.bookings.show', $b->id) }}" class="text-indigo-600 hover:underline">{{ $b->code }}</a></td>
                            <td class="px-4 py-2">{{ $b->customer?->name ?? '—' }} @if ($b->customer)<span class="text-gray-400">({{ $b->customer->phone }})</span>@endif</td>
                            <td class="px-4 py-2">{{ $b->service?->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $currencySymbol }}{{ number_format($b->orderTotalPrice(), 2) }}</td>
                            <td class="px-4 py-2">{{ ucwords(str_replace('_', ' ', $b->status)) }}</td>
                            <td class="px-4 py-2">{{ $b->created_at?->format('d M Y, h:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No cash bookings match your filters.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @endif
    @else
    <x-ui.table>
        <x-slot:footer>{{ $payments->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.sno-th />
                <th class="px-4 py-2">Payer</th>
                <th class="px-4 py-2">Purpose</th>
                <th class="px-4 py-2">Amount</th>
                <th class="px-4 py-2">Gateway</th>
                <th class="px-4 py-2">Order / Payment ID</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Refunded</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Document</th>
                <th class="px-4 py-2 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $p)
                @php
                    // 2026-08-17: extended from a 2-way (booking/user) to a
                    // real 6-way lookup -- the four newer Orderable purposes
                    // each carry their own customer()/franchise() directly.
                    $orderRelation = match ($p->purpose) {
                        'parcel_order' => $p->parcelOrder,
                        'taxi_ride' => $p->taxiRide,
                        'property_reservation' => $p->propertyReservation,
                        'marketplace_order' => $p->marketplaceOrder,
                        default => null,
                    };
                    $payer = $orderRelation?->customer ?? ($p->purpose === 'booking' ? $p->booking?->customer : $p->user);
                    $payerFranchise = $orderRelation?->franchise ?? $p->booking?->franchise ?? $p->user?->franchise;
                @endphp
                <tr class="border-t hover:bg-gray-50">
                    <x-ui.sno :rows="$payments" :loop="$loop" />
                    <td class="px-4 py-2">
                        {{ $payer?->name ?? '—' }}
                        @if ($payer)
                            <span class="text-gray-400">({{ $payer->phone }})</span>
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        <x-ui.badge color="gray">
                            {{ match($p->purpose) {
                                'wallet_topup' => 'Wallet top-up',
                                'plan_subscription' => 'Subscription',
                                'parcel_order' => 'Parcel order',
                                'taxi_ride' => 'Taxi ride',
                                'property_reservation' => 'Property reservation',
                                'marketplace_order' => 'Marketplace order',
                                default => 'Booking',
                            } }}
                        </x-ui.badge>
                        @if ($p->purpose === 'booking' && $p->booking)
                            <span class="text-gray-400 text-xs">{{ $p->booking->code }}</span>
                        @elseif ($orderRelation)
                            <span class="text-gray-400 text-xs">{{ $orderRelation->code }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($p->amount, 2) }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ ucfirst($p->gateway ?? '—') }}</td>
                    <td class="px-4 py-2 text-gray-400 font-mono text-xs">
                        {{ $p->gateway_order_id ?? '—' }}
                        @if ($p->gateway_payment_id)
                            <br>{{ $p->gateway_payment_id }}
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="match($p->status) { 'pending' => 'amber', 'captured' => 'green', 'failed' => 'red', 'refunded' => 'blue', default => 'gray' }">{{ ucfirst($p->status) }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 font-mono text-gray-500">{{ $p->refunded_amount ? $currencySymbol.number_format($p->refunded_amount, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ app(\App\Services\TimezoneResolver::class)->format($p->created_at, $payerFranchise, 'd M Y, h:i A') }}</td>
                    <td class="px-4 py-2"><x-ui.button variant="ghost" size="sm" :href="route('admin.documents.payments.show', $p->id)" target="_blank">View</x-ui.button></td>
                    <td class="px-4 py-2">
                        <x-ui.row-actions :id="$p->id" :archived="$p->trashed()" :can-manage="$canForce && ($p->trashed() || $p->isArchivable())" :can-force="$canForce" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-4 py-6 text-center text-gray-400">No payments match your filters.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
    @endif
</div>
