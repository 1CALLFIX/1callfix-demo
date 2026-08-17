<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ session('message') }}</div>
    @endif

    @if ($order)
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to Orders</x-ui.button>

        <div class="flex items-center justify-between mt-2 mb-4">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ $order->code }}</h1>
                <div class="text-sm text-gray-500 mt-1">
                    {{ $order->customer->name ?? 'Unknown customer' }}
                    &middot; {{ $order->store->name ?? '—' }}
                    &middot; {{ \App\Support\Modules::label($order->module) }}
                    &middot; {{ ucfirst($order->order_type) }}
                </div>
            </div>
            <x-ui.badge size="lg">{{ str_replace('_', ' ', $order->status) }}</x-ui.badge>
        </div>

        @error('action') <div class="mb-4 bg-red-50 text-red-700 text-sm px-4 py-2 rounded">{{ $message }}</div> @enderror

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Items</h3>
            @foreach ($order->items as $item)
                <div class="border-t first:border-t-0 py-2 text-sm flex justify-between">
                    <span>{{ $item->quantity }} &times; {{ $item->product_name_snapshot }}@if ($item->variant_name_snapshot) ({{ $item->variant_name_snapshot }})@endif</span>
                    <span>{{ number_format($item->line_total, 2) }}</span>
                </div>
            @endforeach
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Payment</h3>
            <p class="text-sm text-gray-600">
                Subtotal: {{ number_format($order->subtotal, 2) }}
                &middot; Delivery: {{ number_format($order->delivery_fee, 2) }}
                &middot; Tax: {{ number_format($order->tax_amount, 2) }}
                &middot; Total: {{ number_format($order->total_amount, 2) }}
                @if ($order->price_final) &middot; Final: {{ number_format($order->price_final, 2) }} @endif
                &middot; {{ ucfirst($order->payment_status) }} ({{ $order->payment_method ?? '—' }})
            </p>
        </x-ui.card>

        <x-ui.card class="mb-4">
            <h3 class="font-semibold mb-2">Status history</h3>
            @forelse ($order->statusHistory as $h)
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
            @if ($order->status === 'pending')
                <x-ui.button wire:click="advance('accepted')">Accept</x-ui.button>
            @endif
            @if ($order->status === 'accepted')
                <x-ui.button wire:click="advance('preparing')">Start Preparing</x-ui.button>
            @endif
            @if ($order->status === 'preparing')
                <x-ui.button wire:click="advance('ready')">Mark Ready</x-ui.button>
            @endif
            @if ($order->status === 'ready' && $order->order_type === 'pickup')
                <x-ui.button wire:click="completePickup">Mark Collected</x-ui.button>
            @endif
            @if (! in_array($order->status, ['completed', 'cancelled']))
                <x-ui.button variant="danger" wire:click="cancelOrder" wire:confirm="Cancel this order?">Cancel</x-ui.button>
            @endif
        </div>
    @else
        <h1 class="text-2xl font-bold mb-4">Marketplace Orders</h1>

        <div class="flex gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search order code or customer..." class="border rounded px-3 py-2 text-sm w-96">
            <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach (['pending','accepted','preparing','ready','completed','cancelled'] as $s)
                    <option value="{{ $s }}">{{ str_replace('_', ' ', $s) }}</option>
                @endforeach
            </select>
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $orders->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Store</th>
                    <th class="px-4 py-2">Total</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $o)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $o->code }}</td>
                        <td class="px-4 py-2">{{ $o->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $o->store->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ number_format($o->total_amount, 2) }}</td>
                        <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', $o->status) }}</x-ui.badge></td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewOrder({{ $o->id }})">View</x-ui.button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
