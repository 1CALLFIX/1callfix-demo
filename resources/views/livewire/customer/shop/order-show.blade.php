<div class="mb-bottom-nav" @if ($open) wire:poll.15s @endif>
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <a href="{{ route('customer.shop.orders') }}" wire:navigate class="text-sm text-blue-700">← Shop orders</a>

        <header class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ $order->store->name }}</h1>
                <p class="text-sm text-slate-500">Order {{ $order->code }} · {{ $order->order_type === 'pickup' ? 'Pickup at shop' : 'Delivery' }}</p>
            </div>
            <x-shop.order-status :order="$order" class="!text-sm" />
        </header>

        @if ($flash)
            <p role="status" @class(['mt-4 rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flash }}</p>
        @endif

        {{-- Progress: one line per step, the current one highlighted. --}}
        @php
            $steps = $order->order_type === 'pickup'
                ? ['pending' => 'Order placed', 'accepted' => 'Shop accepted', 'preparing' => 'Being packed', 'ready' => 'Ready to collect', 'completed' => 'Collected']
                : ['pending' => 'Order placed', 'accepted' => 'Shop accepted', 'preparing' => 'Being packed', 'ready' => 'With the rider', 'completed' => 'Delivered'];
            $keys = array_keys($steps);
            $at = array_search($order->status, $keys, true);
        @endphp
        @if ($order->status !== 'cancelled')
            <ol class="mt-5 grid grid-cols-5 gap-1 text-center text-[11px] font-medium">
                @foreach ($steps as $key => $label)
                    @php $i = array_search($key, $keys, true); @endphp
                    <li>
                        <span @class(['mx-auto block h-1.5 rounded-full', 'bg-blue-600' => $at !== false && $i <= $at, 'bg-slate-200' => $at === false || $i > $at])></span>
                        <span @class(['mt-1 block', 'text-slate-900' => $at !== false && $i <= $at, 'text-slate-400' => $at === false || $i > $at])>{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        @else
            <p class="mt-5 rounded-2xl bg-red-50 p-4 text-sm text-red-800">This order was cancelled.@if ($order->cancellation_note) Reason: {{ $order->cancellation_note }}@endif
                @if (in_array($order->payment_status, ['refunded', 'partially_refunded'], true)) Refund: issued to where you paid. @endif</p>
        @endif

        @if ($order->isAwaitingOnlinePayment())
            <div class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Payment not received yet.</p>
                <p class="mt-0.5">The shop starts once you pay. Unpaid orders are cancelled after {{ \App\Support\MarketplaceSettings::get('marketplace.unpaid_expiry_minutes') }} minutes.</p>
                <button type="button" wire:click="payNow" class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 font-bold text-white">Pay {{ $currencySymbol }}{{ number_format((float) $order->total_amount, 2) }}</button>
            </div>
        @endif

        @if ($showCode)
            <div class="mt-4 rounded-2xl bg-slate-900 p-5 text-center text-white">
                <p class="text-xs uppercase tracking-widest text-slate-300">{{ $order->order_type === 'pickup' ? 'Show this code at the shop' : 'Give this code to your rider' }}</p>
                <p class="mt-1 text-4xl font-bold tracking-[0.3em] tabular-nums">{{ $order->delivery_otp }}</p>
                @if ($order->order_type === 'delivery' && $order->assignedWorker)
                    <p class="mt-2 text-sm text-slate-300">Rider: {{ $order->assignedWorker->user?->name }}</p>
                @endif
            </div>
        @endif

        <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-4 text-sm sm:p-5">
            <ul class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-3 py-1.5">
                        <span>{{ $item->quantity }} × {{ $item->product_name_snapshot }}@if ($item->variant_name_snapshot) <span class="text-slate-500">({{ $item->variant_name_snapshot }})</span>@endif</span>
                        <span class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $item->line_total, 2) }}</span>
                    </li>
                @endforeach
            </ul>
            <dl class="mt-2 space-y-1 border-t border-slate-100 pt-2">
                <div class="flex justify-between"><dt>Items</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $order->subtotal, 2) }}</dd></div>
                @if ($order->order_type === 'delivery')
                    <div class="flex justify-between"><dt>Delivery</dt><dd class="tabular-nums">{{ (float) $order->delivery_fee > 0 ? $currencySymbol.number_format((float) $order->delivery_fee, 2) : 'Free' }}</dd></div>
                @endif
                @if ((float) $order->tax_amount > 0)
                    <div class="flex justify-between"><dt>Tax</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $order->tax_amount, 2) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold"><dt>Total</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $order->total_amount, 2) }}</dd></div>
                <div class="flex justify-between text-slate-500"><dt>Paid by</dt><dd>{{ ['online' => 'Online', 'wallet' => 'Wallet', 'cash' => 'Cash'][$order->payment_method] ?? $order->payment_method }}</dd></div>
            </dl>
            @if ($order->order_type === 'delivery' && $order->deliveryAddress)
                <p class="mt-3 text-slate-600">To: {{ $order->deliveryAddress->address_line }}</p>
            @endif
        </section>

        @if ($canCancel)
            <div class="mt-4">
                @if ($confirmingCancel)
                    <form wire:submit="cancel" class="space-y-2 rounded-2xl border border-red-200 bg-red-50 p-4">
                        <label for="cancel-reason" class="block text-sm font-medium text-red-900">Why are you cancelling?</label>
                        <input id="cancel-reason" type="text" wire:model="cancelReason" class="min-h-11 w-full rounded-xl border border-red-300 px-3 text-base">
                        @error('cancelReason') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <p class="text-xs text-red-800">{{ $order->status === 'pending' ? 'Free: the shop has not accepted yet.' : 'The shop has accepted; a cancellation fee may apply.' }}</p>
                        <div class="flex gap-2">
                            <button type="submit" class="min-h-11 rounded-xl bg-red-600 px-4 text-sm font-bold text-white">Cancel order</button>
                            <button type="button" wire:click="$set('confirmingCancel', false)" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm">Keep it</button>
                        </div>
                    </form>
                @else
                    <button type="button" wire:click="$set('confirmingCancel', true)" class="text-sm font-medium text-red-700 hover:underline">Cancel this order</button>
                @endif
            </div>
        @endif

        @if ($order->status === 'completed')
            <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                @if ($review)
                    <p class="text-sm">You rated {{ $order->store->name }} <span class="font-bold text-amber-600">{{ str_repeat('★', $review->rating) }}</span></p>
                @else
                    <form wire:submit="rate">
                        <h2 class="font-semibold">How was {{ $order->store->name }}?</h2>
                        <div class="mt-2 flex gap-1" role="radiogroup" aria-label="Rating">
                            @for ($s = 1; $s <= 5; $s++)
                                <button type="button" wire:click="$set('rating', {{ $s }})" role="radio" aria-checked="{{ $rating === $s ? 'true' : 'false' }}" aria-label="{{ $s }} stars"
                                        @class(['text-3xl', 'text-amber-500' => $rating >= $s, 'text-slate-300' => $rating < $s])>★</button>
                            @endfor
                        </div>
                        @error('rating') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <textarea wire:model="comment" rows="2" placeholder="Anything to tell the shop? (optional)" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-base"></textarea>
                        <button type="submit" class="mt-2 min-h-11 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white">Send rating</button>
                    </form>
                @endif
                <p class="mt-3 text-xs text-slate-500">Damaged or wrong item? You can ask for a return within {{ $returnDays }} days.</p>
            </section>
        @endif
    </div>

    @assets
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    @endassets
    @script
    <script>
        $wire.on('razorpay-open', (e) => {
            const o = e.order ?? e[0]?.order;
            const redirect = e.redirect ?? e[0]?.redirect;
            if (! o || ! window.Razorpay) { if (redirect) window.location = redirect; return; }
            new window.Razorpay({
                key: o.razorpay_key_id, order_id: o.razorpay_order_id, amount: o.amount, currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                description: @js('Order '.$order->code),
                handler: () => { window.location = redirect; },
                modal: { ondismiss: () => { window.location = redirect; } },
            }).open();
        });
    </script>
    @endscript
</div>
