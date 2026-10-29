<div class="space-y-4" wire:poll.20s>
    <h1 class="text-2xl font-bold tracking-tight">Orders</h1>

    <div class="flex flex-wrap gap-2 text-sm" role="tablist">
        @foreach (['new' => 'New', 'active' => 'In progress', 'done' => 'Completed', 'cancelled' => 'Cancelled', 'returns' => 'Returns'] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-slate-900 text-white' => $tab === $key, 'border border-slate-300 bg-white text-slate-700' => $tab !== $key])>
                {{ $label }}@if (isset($counts[$key]) && $counts[$key] > 0) <span class="ml-1 rounded-full bg-amber-500 px-1.5 text-xs text-white">{{ $counts[$key] }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    @if ($tab === 'returns')
        {{-- E-commerce Phase 3b: returns of this shop's orders. The refund comes out of the order's held earnings. --}}
        @forelse ($returns as $r)
            <x-ui.card class="!p-4" wire:key="return-{{ $r->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold">Return #{{ $r->id }} · order {{ $r->order?->code }}</p>
                        <p class="text-sm text-slate-600">{{ \App\Models\MarketplaceReturn::REASONS[$r->reason] ?? $r->reason }}: {{ $r->description }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold">{{ ucfirst($r->status) }}</span>
                </div>
                <div class="mt-2 flex gap-2">@foreach ($r->photoUrls() as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="Return photo" class="h-16 w-16 rounded-lg object-cover"></a>@endforeach</div>
                @if ($r->status === 'accepted')
                    <p class="mt-2 text-sm text-slate-700">Accepted by the {{ \App\Models\Setting::get('branding.platform_name', '1CallFix') }} team. When the item is back with you, confirm it.</p>
                    <x-ui.button class="mt-2" wire:click="receiveReturn({{ $r->id }})">Item received back</x-ui.button>
                @elseif ($r->status === 'refunded')
                    <p class="mt-2 text-sm text-slate-600">Refunded {{ $currencySymbol }}{{ number_format((float) $r->refund_amount, 2) }}; your share of it was taken from this order's held earnings.</p>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-600">No returns.</p></x-ui.card>
        @endforelse
    @else
    @forelse ($orders as $order)
        @php
            $awaitingPayment = $order->isAwaitingOnlinePayment();
            $isPickup = $order->order_type === 'pickup';
        @endphp
        <x-ui.card class="!p-4" wire:key="order-{{ $order->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-semibold">{{ $order->code }} · {{ $isPickup ? 'Pickup at shop' : 'Delivery' }}</p>
                    <p class="text-xs text-slate-500">{{ $order->customer?->name }} · {{ app(\App\Services\TimezoneResolver::class)->format($order->created_at, $order->franchise, 'd M, h:i A') }} · {{ $order->created_at->diffForHumans(short: true) }}</p>
                </div>
                <div class="text-right">
                    <p class="text-lg font-bold">{{ $currencySymbol }}{{ number_format((float) $order->total_amount, 2) }}</p>
                    <p class="text-xs">
                        @if ($order->payment_method === 'cash')
                            <span class="font-semibold text-amber-700">Cash {{ $isPickup ? 'at pickup' : 'on delivery' }}</span>
                        @elseif ($order->payment_status === 'paid')
                            <span class="font-semibold text-emerald-700">Paid {{ $order->payment_method === 'wallet' ? '(wallet)' : 'online' }}</span>
                        @else
                            <span class="font-semibold text-slate-500">Waiting for payment</span>
                        @endif
                    </p>
                </div>
            </div>

            <ul class="mt-3 divide-y divide-slate-100 rounded-lg bg-slate-50 px-3 text-sm">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-3 py-1.5">
                        <span><span class="font-semibold">{{ $item->quantity }} ×</span> {{ $item->product_name_snapshot }}@if ($item->variant_name_snapshot) <span class="text-slate-500">({{ $item->variant_name_snapshot }})</span>@endif</span>
                        <span class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $item->line_total, 2) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($order->special_instructions)
                <p class="mt-2 text-sm text-slate-700"><span class="font-medium">Note:</span> {{ $order->special_instructions }}</p>
            @endif
            @if (! $isPickup && $order->deliveryAddress)
                <p class="mt-2 text-xs text-slate-500">Deliver to: {{ $order->deliveryAddress->city ?: $order->deliveryAddress->address_line }}</p>
            @endif
            @if ($order->status === 'ready' && ! $isPickup)
                <p class="mt-2 text-sm text-slate-700">
                    {{ $order->assignedWorker ? 'Rider '.$order->assignedWorker->user?->name.' is coming to collect it.' : 'Finding a rider…' }}
                </p>
            @endif
            @if ($order->status === 'completed')
                <a href="{{ route('customer.shop.orders.invoice', $order) }}" target="_blank" rel="noopener" class="mt-2 inline-block text-sm font-medium text-blue-700">Tax invoice →</a>
            @endif
            @if ($order->status === 'cancelled' && $order->cancellation_note)
                <p class="mt-2 text-sm text-slate-600">Cancelled: {{ $order->cancellation_note }}</p>
            @endif

            {{-- Next step for the seller. --}}
            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if ($order->status === 'pending')
                    @if ($awaitingPayment)
                        <p class="text-sm text-slate-500">You can accept this once the customer has paid.</p>
                    @else
                        <x-ui.button wire:click="advance({{ $order->id }}, 'accepted')">Accept order</x-ui.button>
                    @endif
                @elseif ($order->status === 'accepted')
                    <x-ui.button wire:click="advance({{ $order->id }}, 'preparing')">Start packing</x-ui.button>
                @elseif ($order->status === 'preparing')
                    <x-ui.button wire:click="advance({{ $order->id }}, 'ready')">{{ $isPickup ? 'Ready for pickup' : 'Packed — call a rider' }}</x-ui.button>
                @elseif ($order->status === 'ready' && $isPickup)
                    <label class="sr-only" for="code-{{ $order->id }}">Collection code</label>
                    <input id="code-{{ $order->id }}" type="text" inputmode="numeric" maxlength="8" wire:model="codes.{{ $order->id }}" placeholder="Customer's code"
                           class="min-h-10 w-36 rounded-lg border border-slate-300 px-3 text-base">
                    <x-ui.button wire:click="handOver({{ $order->id }})">Hand over</x-ui.button>
                @endif

                @if (in_array($order->status, ['pending', 'accepted', 'preparing'], true))
                    <button type="button" wire:click="startReject({{ $order->id }})" class="text-sm font-medium text-red-700 hover:underline">Can't fill this order</button>
                @endif
            </div>

            @if ($rejectingId === $order->id)
                <form wire:submit="reject" class="mt-3 space-y-2 rounded-lg border border-red-200 bg-red-50 p-3">
                    <label for="reject-{{ $order->id }}" class="block text-sm font-medium text-red-900">Why can't you fill it? The customer sees this and is refunded in full.</label>
                    <input id="reject-{{ $order->id }}" type="text" wire:model="rejectReason" class="min-h-10 w-full rounded-lg border border-red-300 px-3 text-base" placeholder="For example: out of stock">
                    @error('rejectReason') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <x-ui.button type="submit" variant="danger">Cancel order</x-ui.button>
                        <x-ui.button type="button" variant="secondary" wire:click="$set('rejectingId', null)">Keep it</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card><p class="text-sm text-slate-600">{{ $tab === 'new' ? 'No new orders right now. New orders ring here.' : 'Nothing here yet.' }}</p></x-ui.card>
    @endforelse

    {{ $orders->links() }}
    @endif
</div>
