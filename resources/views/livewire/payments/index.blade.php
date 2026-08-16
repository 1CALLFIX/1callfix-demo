<div>
    <h1 class="text-2xl font-bold mb-1">Payments</h1>
    <div class="text-xs text-gray-400 mb-4">Gateway: {{ $gatewayDisplayName }}</div>

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search order/payment id, booking code, name or phone..." class="border rounded px-3 py-2 text-sm w-80">
        <select wire:model.live="purposeFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All purposes</option>
            <option value="booking">Booking</option>
            <option value="wallet_topup">Wallet top-up</option>
            <option value="plan_subscription">Plan subscription</option>
        </select>
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="captured">Captured</option>
            <option value="failed">Failed</option>
            <option value="refunded">Refunded</option>
        </select>
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $payments->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Payer</th>
                <th class="px-4 py-2">Purpose</th>
                <th class="px-4 py-2">Amount</th>
                <th class="px-4 py-2">Gateway</th>
                <th class="px-4 py-2">Order / Payment ID</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Refunded</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Document</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $p)
                @php
                    $payer = $p->purpose === 'booking' ? $p->booking?->customer : $p->user;
                @endphp
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2">
                        {{ $payer?->name ?? '—' }}
                        @if ($payer)
                            <span class="text-gray-400">({{ $payer->phone }})</span>
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        <x-ui.badge color="gray">
                            {{ match($p->purpose) { 'wallet_topup' => 'Wallet top-up', 'plan_subscription' => 'Subscription', default => 'Booking' } }}
                        </x-ui.badge>
                        @if ($p->purpose === 'booking' && $p->booking)
                            <span class="text-gray-400 text-xs">{{ $p->booking->code }}</span>
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
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ app(\App\Services\TimezoneResolver::class)->format($p->created_at, $p->booking?->franchise ?? $p->user?->franchise, 'd M Y, h:i A') }}</td>
                    <td class="px-4 py-2"><x-ui.button variant="ghost" size="sm" :href="route('admin.documents.payments.show', $p->id)" target="_blank">View</x-ui.button></td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">No payments match your filters.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
