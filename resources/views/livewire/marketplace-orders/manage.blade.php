<div>
    @if (session('message'))
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded" data-flash>{{ session('message') }}</div>
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

        {{-- REF 1CF-JOURNEY-001 — the order's journey; the raw log stays below. --}}
        @php($journey = \App\Support\Journey\JourneyBuilder::build(\App\Support\Journey\JourneyCatalog::journeyForMarketplaceModule($order->module), $order->status, $order->statusHistory))
        @if ($journey)
            <x-ui.card class="mb-4">
                <x-journey.timeline :journey="$journey" variant="compact" />
            </x-ui.card>
        @endif

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

        @if ($order->order_type === 'delivery' && $order->pickup_code)
            <p class="mb-3 text-sm text-gray-600" data-testid="admin-pickup-code">Pickup code <span class="font-bold tracking-widest text-gray-900">{{ $order->pickup_code }}</span>
                · {{ $order->picked_up_at ? 'handed to the rider '.$order->picked_up_at->diffForHumans() : 'not handed over yet' }}</p>
        @endif
        {{-- Owner 2026-11-09: ring a nearby 1CallFix rider by hand (he still accepts on his phone). --}}
        @if ($order->order_type === 'delivery' && $order->assigned_worker_id === null && in_array($order->status, ['preparing', 'ready'], true))
            <x-ui.card class="mb-4" data-testid="admin-pick-rider">
                <h3 class="font-semibold">Pick a rider</h3>
                <p class="mb-2 text-sm text-gray-500">Online riders near the {{ $order->isFood() ? 'restaurant' : 'shop' }}, nearest first. The rider's phone rings and he accepts as usual.</p>
                @forelse ($pickerRiders as $c)
                    <div class="flex items-center justify-between border-t py-2 text-sm">
                        <span><span class="font-medium">{{ $c['provider']->user?->name ?? 'Rider #'.$c['provider']->id }}</span> <span class="text-gray-500">· {{ rtrim(rtrim(number_format((float) $c['distance_km'], 1), '0'), '.') }} km</span></span>
                        <span class="flex gap-2">
                            <x-ui.button wire:click="ringRider({{ $c['provider']->id }})">Ring</x-ui.button>
                            @if ($this->canEmergencyOverride($order))
                                <x-ui.button variant="secondary" wire:click="assignRiderNow({{ $c['provider']->id }})" wire:confirm="Give this order to this rider now, without waiting for them to accept?">Assign now</x-ui.button>
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-amber-700">No rider is online nearby right now (a rider shows here while his rider app is open).</p>
                @endforelse
            </x-ui.card>
        @endif

        @php($canOverride = ! in_array($order->status, ['completed', 'cancelled'], true) && $this->canEmergencyOverride($order))
        @if ($canOverride)
            {{-- Owner 2026-11-12: every admin status change / direct assign needs a reason; it goes in the history with the admin's name. --}}
            <div class="mb-3">
                <label class="text-sm text-gray-600">Reason for an admin change (required, goes in the history)</label>
                <input type="text" wire:model="emergencyReason" maxlength="500" placeholder="e.g. restaurant's tablet is not working" class="mt-1 w-full border rounded px-3 py-2 text-sm">
            </div>
        @endif

        <div class="flex gap-2">
            @if ($canOverride)
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
                @if ($order->status === 'ready' && $order->order_type === 'delivery' && $order->assigned_worker_id)
                    <x-ui.button wire:click="emergencyDelivered" wire:confirm="Mark this order delivered for the rider?">Mark Delivered</x-ui.button>
                @endif
            @endif
            @if (! in_array($order->status, ['completed', 'cancelled']))
                <x-ui.button variant="danger" wire:click="cancelOrder" wire:confirm="Cancel this order?">Cancel</x-ui.button>
            @endif
        </div>
    @else
        <h1 class="text-2xl font-bold mb-4">{{ $pageTitle }}</h1>
        <x-ui.filter-tabs class="mb-3" :tabs="['' => 'Shop + Food', 'shop' => 'Shop', 'food' => 'Food']" :active="$moduleFilter" model="moduleFilter" />

        <div class="flex gap-3 mb-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search order code or customer..." class="border rounded px-3 py-2 text-sm w-96">
            <x-ui.filter-tabs class="w-full" :tabs="['' => 'All'] + collect(['pending','accepted','preparing','ready','completed','cancelled'])->mapWithKeys(fn ($s) => [$s => ucfirst(str_replace('_', ' ', $s))])->all()" :active="$statusFilter" model="statusFilter" />
            <button type="button" wire:click="$toggle('attempts')" title="Online payments started but not completed. Not orders: the shop never saw them; they end on their own." class="shrink-0 rounded px-3 py-1.5 text-sm {{ $attempts ? 'bg-amber-600 text-white' : 'border border-amber-300 bg-white text-amber-800' }}">{{ $attempts ? '← Back to orders' : 'Payment attempts (not orders)' }}</button>
        </div>

        <x-ui.table>
            <x-slot:footer>{{ $orders->links() }}</x-slot:footer>

            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <x-ui.sno-th />
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Store</th>
                    <th class="px-4 py-2">Total</th>
                    <th class="px-4 py-2">Status</th>
                    <x-ui.tat-heads />
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $o)
                    <tr class="border-t">
                        <x-ui.sno :rows="$orders" :loop="$loop" />
                        <td class="px-4 py-2 font-mono text-xs">{{ $o->code }}</td>
                        <td class="px-4 py-2">{{ $o->customer->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $o->store->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ number_format($o->total_amount, 2) }}</td>
                        <td class="px-4 py-2"><x-ui.badge>{{ str_replace('_', ' ', $o->status) }}</x-ui.badge></td>
                        <x-ui.tat-cells :record="$o" done="completed_at" />
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="viewOrder({{ $o->id }})">View</x-ui.button></td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
