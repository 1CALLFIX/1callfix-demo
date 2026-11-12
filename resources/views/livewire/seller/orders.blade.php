<div class="space-y-4" wire:poll.20s>
    <h1 class="text-2xl font-bold tracking-tight">Orders</h1>
    <x-partner.book-rider-button />

    <div class="flex flex-wrap gap-2 text-sm" role="tablist">
        {{-- Owner 2026-11-09: one tab per step of the order. --}}
        @foreach (['new' => 'New', 'cooking' => $isRestaurant ? 'Cooking' : 'Packing', 'ready' => 'Ready', 'handed' => 'Handed over', 'done' => 'Completed', 'cancelled' => 'Cancelled', 'returns' => $isRestaurant ? 'Problems' : 'Returns'] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-slate-900 text-white' => $activeTab === $key, 'border border-slate-300 bg-white text-slate-700' => $activeTab !== $key])>
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
                        <p class="font-semibold">{{ $isRestaurant ? 'Problem' : 'Return' }} #{{ $r->id }} · order {{ $r->order?->code }}</p>
                        <p class="text-sm text-slate-600">{{ ($isRestaurant ? \App\Models\MarketplaceReturn::FOOD_REASONS : \App\Models\MarketplaceReturn::REASONS)[$r->reason] ?? $r->reason }}: {{ $r->description }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold">{{ $isRestaurant && $r->status === 'received' ? 'Accepted' : ucfirst($r->status) }}</span>
                </div>
                <div class="mt-2 flex gap-2">@foreach ($r->photoUrls() as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="Return photo" class="h-16 w-16 rounded-lg object-cover"></a>@endforeach</div>
                @if ($isRestaurant && $r->restaurant_respond_by)
                    {{-- Food problem rules 2026-11-03: the restaurant agrees or disputes before the deadline. --}}
                    @php $answer = $r->restaurantAnswer(); @endphp
                    @if ($r->status === 'requested' && $answer === 'waiting')
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm" data-testid="problem-answer-{{ $r->id }}">
                            <p class="font-semibold text-amber-900">Do you agree? Answer by {{ $r->respondByLocal() }}. No answer counts as Agree.</p>
                            <p class="mt-1 text-xs text-amber-900">If you agree and the team finds it was your mistake, your part of the refund comes out of this order's earnings.</p>
                            <div class="mt-2 flex flex-wrap items-start gap-2">
                                <x-ui.button wire:click="answerProblem({{ $r->id }}, 'agree')">Agree</x-ui.button>
                                <input type="text" wire:model="disputeNotes.{{ $r->id }}" maxlength="500" placeholder="Why not? (e.g. packed and sealed, photo taken)" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2">
                                <x-ui.button variant="secondary" wire:click="answerProblem({{ $r->id }}, 'dispute')">Dispute</x-ui.button>
                            </div>
                        </div>
                    @elseif ($answer)
                        <p class="mt-2 text-sm text-slate-700">Your answer: {{ ['agreed' => 'Agreed', 'disputed' => 'Disputed', 'no_reply' => 'No answer in time (counts as Agree)', 'waiting' => 'Waiting'][$answer] }}@if ($r->restaurant_note) — "{{ $r->restaurant_note }}"@endif</p>
                    @endif
                @endif
                @if ($r->status === 'accepted' && $r->pickup_status === 'picked')
                    {{-- Shop go-live: a rider is bringing it back. The drop code closes it and pays the rider. --}}
                    <div class="mt-2 rounded-xl bg-blue-50 p-3 text-sm text-blue-900">
                        <p class="font-semibold">A rider is bringing this item back. Check it, then give the rider this drop code:</p>
                        <p class="mt-1 text-2xl font-bold tracking-widest">{{ $r->drop_otp }}</p>
                        <p class="mt-1 text-xs">The rider's fee ({{ $currencySymbol }}{{ number_format((float) $r->pickup_fee, 2) }}) comes out of this order's held earnings.</p>
                    </div>
                @elseif ($r->status === 'accepted' && in_array($r->pickup_status, ['open', 'assigned'], true))
                    <p class="mt-2 text-sm text-slate-700">Accepted. {{ $r->pickup_status === 'open' ? 'A rider will collect it from the customer' : 'A rider is on the way to the customer' }} and bring it to you. The rider's fee ({{ $currencySymbol }}{{ number_format((float) $r->pickup_fee, 2) }}) comes out of this order's held earnings.</p>
                    @if ($r->pickup_status === 'open')
                        <p class="mt-1 text-xs text-slate-500">Did the customer bring it to you instead? Then confirm it here.</p>
                        <x-ui.button class="mt-2" wire:click="receiveReturn({{ $r->id }})">Item received back</x-ui.button>
                    @endif
                @elseif ($r->status === 'accepted')
                    <p class="mt-2 text-sm text-slate-700">Accepted by the {{ \App\Models\Setting::get('branding.platform_name', '1CallFix') }} team. When the item is back with you, confirm it.</p>
                    <x-ui.button class="mt-2" wire:click="receiveReturn({{ $r->id }})">Item received back</x-ui.button>
                @elseif ($isRestaurant && $r->status === 'received')
                    {{-- Food F4: nothing comes back; the refund is being approved. --}}
                    <p class="mt-2 text-sm text-slate-700">Accepted by the {{ \App\Models\Setting::get('branding.platform_name', '1CallFix') }} team: {{ \App\Models\MarketplaceReturn::FAULTS[$r->fault] ?? '' }}. The refund is being approved.</p>
                @elseif ($isRestaurant && $r->status === 'refunded')
                    <p class="mt-2 text-sm text-slate-600">Refunded {{ $currencySymbol }}{{ number_format((float) $r->refund_amount, 2) }} ({{ \App\Models\MarketplaceReturn::FAULTS[$r->fault] ?? '' }}). Taken from this order's earnings: {{ $currencySymbol }}{{ number_format((float) $r->restaurant_paid, 2) }}.</p>
                @elseif ($r->status === 'refunded')
                    <p class="mt-2 text-sm text-slate-600">Refunded {{ $currencySymbol }}{{ number_format((float) $r->refund_amount, 2) }}; your share of it was taken from this order's held earnings.</p>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-600">{{ $isRestaurant ? 'No problems reported.' : 'No returns.' }}</p></x-ui.card>
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
                    @if ($order->status !== 'cancelled')
                        <a href="{{ route('seller.orders.slip', [$order, 'print' => 1]) }}" target="_blank" rel="noopener" class="mt-1 inline-flex min-h-9 items-center text-xs font-semibold text-blue-700 underline" data-testid="kitchen-slip-link">🖨 Print kitchen slip</a>
                    @endif
                    {{-- Owner 2026-11-09: where the order is, at a glance. --}}
                    @unless (in_array($order->status, ['pending', 'cancelled'], true))
                        @php
                            $steps = $isPickup
                                ? ['Accepted', $order->isFood() ? 'Cooking' : 'Packing', 'Ready', 'Collected']
                                : ['Accepted', $order->isFood() ? 'Cooking' : 'Packing', 'Ready', 'Handed over', 'Delivered'];
                            $at = match (true) {
                                $order->status === 'completed' => count($steps) - 1,
                                $order->status === 'ready' && $order->picked_up_at !== null => 3,
                                $order->status === 'ready' => 2,
                                $order->status === 'preparing' => 1,
                                default => 0,
                            };
                        @endphp
                        <ol class="mt-2 flex flex-wrap items-center gap-1 text-[11px] font-semibold" data-testid="order-steps">
                            @foreach ($steps as $i => $step)
                                <li @class(['rounded-full px-2 py-0.5', 'bg-emerald-600 text-white' => $i === $at, 'bg-emerald-50 text-emerald-700' => $i < $at, 'bg-slate-100 text-slate-400' => $i > $at])>{{ $step }}</li>
                            @endforeach
                        </ol>
                    @endunless
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
                        <span><span class="font-semibold">{{ $item->quantity }} ×</span> {{ $item->product_name_snapshot }}@if ($item->variant_name_snapshot) <span class="text-slate-500">({{ $item->variant_name_snapshot }})</span>@endif
                            @if (! empty($item->add_ons_snapshot))<span class="block text-xs text-slate-600">+ {{ collect($item->add_ons_snapshot)->pluck('name')->implode(', ') }}</span>@endif</span>
                        <span class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $item->line_total, 2) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($order->special_instructions)
                <p class="mt-2 text-sm text-slate-700"><span class="font-medium">Note:</span> {{ $order->special_instructions }}</p>
            @endif
            @if (! $isPickup && $order->deliveryAddress)
                <p class="mt-2 text-xs text-slate-500">Deliver to: {{ $order->deliveryAddress->city ?: $order->deliveryAddress->address_line }}@if ($order->recipient_name) · for {{ $order->recipient_name }}@endif</p>
            @endif
            {{-- Food F2: the accept clock, the promised time, and a rider called while cooking. --}}
            @if ($order->isFood() && $order->status === 'pending' && $order->accept_by)
                <p class="mt-2 text-sm font-semibold text-red-700">Accept by {{ app(\App\Services\TimezoneResolver::class)->format($order->accept_by, $order->franchise, 'h:i A') }} ({{ $order->accept_by->isPast() ? 'time is up' : $order->accept_by->diffForHumans(short: true) }}), or it is cancelled and refunded.</p>
            @endif
            @if ($order->isFood() && in_array($order->status, ['accepted', 'preparing'], true) && $order->ready_by)
                <p class="mt-2 text-sm text-slate-700">You promised it by <span class="font-semibold">{{ $order->readyByLocal() }}</span>@if ($order->ready_by->isPast()) <span class="font-semibold text-red-700">(late)</span>@endif.</p>
            @endif
            @if (! $isPickup && ($order->status === 'ready' || ($order->status === 'preparing' && $order->rider_called_at)))
                <p class="mt-2 text-sm text-slate-700">
                    {{ $order->assignedWorker
                        ? ($order->picked_up_at ? $order->assignedWorker->user?->name.' is delivering.' : ($order->rider_at_store_at ? $order->assignedWorker->user?->name.' is at the counter.' : $order->assignedWorker->user?->name.' is coming.'))
                        : 'Finding a rider…' }}
                </p>
            @endif
            {{-- Owner 2026-11-09: the pickup code. Write it on the bag / type it into your bill; the rider says it at the
                 counter and you type it here before handing over. --}}
            @if (! $isPickup && in_array($order->status, ['accepted', 'preparing', 'ready'], true))
                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3" data-testid="pickup-code">
                    @if ($order->picked_up_at)
                        <p class="text-sm font-semibold text-emerald-800">Picked up by {{ $order->assignedWorker?->user?->name ?? 'the rider' }} at {{ app(\App\Services\TimezoneResolver::class)->format($order->picked_up_at, $order->franchise, 'h:i A') }}.</p>
                    @else
                        <p class="text-sm text-slate-600">Pickup code <span class="ml-1 text-2xl font-extrabold tracking-[0.2em] text-slate-900">{{ $order->ensurePickupCode() }}</span></p>
                        <p class="text-xs text-slate-500">Write on the bag. Give only to the rider with this code.</p>
                        @if ($order->assigned_worker_id && $order->status === 'ready' && ! $order->rider_at_store_at)
                            <p class="mt-2 text-xs font-semibold text-slate-500">Hand over when the rider arrives and tells the code.</p>
                        @elseif ($order->assigned_worker_id && $order->status === 'ready')
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <label for="rider-code-{{ $order->id }}" class="sr-only">Code the rider tells you</label>
                                <input id="rider-code-{{ $order->id }}" type="text" inputmode="numeric" maxlength="8" placeholder="Rider's code"
                                       wire:model="riderCodes.{{ $order->id }}" class="min-h-11 w-32 rounded-lg border border-slate-300 px-3 text-lg tracking-widest">
                                <x-ui.button wire:click="handToRider({{ $order->id }})">Handed over</x-ui.button>
                            </div>
                        @endif
                    @endif
                </div>
            @endif
            {{-- Owner 2026-11-09: ring a nearby 1CallFix rider yourself (he still accepts on his phone). --}}
            @if (! $isPickup && $order->assigned_worker_id === null && in_array($order->status, ['preparing', 'ready'], true))
                <div class="mt-2" data-testid="pick-rider">
                    <button type="button" wire:click="togglePicker({{ $order->id }})" class="text-sm font-semibold text-blue-700 hover:underline">
                        {{ $pickingFor === $order->id ? 'Hide riders' : 'Pick a rider' }}
                    </button>
                    @if ($pickingFor === $order->id)
                        @if ($pickerRiders->isEmpty())
                            <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">No rider online nearby.</p>
                        @else
                            <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                                @foreach ($pickerRiders as $c)
                                    <li class="flex items-center justify-between gap-3 px-3 py-2">
                                        <span class="min-w-0 text-sm">
                                            <span class="font-semibold text-slate-900">{{ $c['provider']->user?->name ?? 'Rider #'.$c['provider']->id }}</span>
                                            <span class="text-slate-500">· {{ rtrim(rtrim(number_format((float) $c['distance_km'], 1), '0'), '.') }} km</span>
                                        </span>
                                        <x-ui.button wire:click="ringRider({{ $order->id }}, {{ $c['provider']->id }})">Ring</x-ui.button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>
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
                    @elseif ($order->isFood())
                        <label for="prep-{{ $order->id }}" class="text-sm">Ready in</label>
                        <input id="prep-{{ $order->id }}" type="number" min="1" max="240" wire:model="prepTimes.{{ $order->id }}" placeholder="{{ $usualPrep }}"
                               class="min-h-10 w-20 rounded-lg border border-slate-300 px-2 text-base">
                        <span class="text-sm">min</span>
                        <x-ui.button wire:click="advance({{ $order->id }}, 'accepted')">Accept order</x-ui.button>
                    @else
                        <x-ui.button wire:click="advance({{ $order->id }}, 'accepted')">Accept order</x-ui.button>
                    @endif
                @elseif ($order->status === 'accepted')
                    <x-ui.button wire:click="advance({{ $order->id }}, 'preparing')">{{ $order->isFood() ? 'Start cooking' : 'Start packing' }}</x-ui.button>
                @elseif ($order->status === 'preparing')
                    <x-ui.button wire:click="advance({{ $order->id }}, 'ready')">Mark ready</x-ui.button>
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
