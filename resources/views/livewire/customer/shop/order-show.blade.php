@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
<div class="mb-bottom-nav" @if ($open) wire:poll.15s @endif>
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <a href="{{ route('customer.shop.orders') }}" wire:navigate class="text-sm text-blue-700">{{ $t('shop_order.back') }}</a>

        <header class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ $order->store->name }}</h1>
                <p class="text-sm text-slate-500">{{ $t($order->order_type === 'pickup' ? 'shop_order.code_pickup' : 'shop_order.code_delivery', ['code' => $order->code]) }}</p>
            </div>
            <x-shop.order-status :order="$order" class="!text-sm" />
        </header>

        @if ($flash)
            <p role="status" @class(['mt-4 rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flash }}</p>
        @endif

        {{-- Progress: one line per step, the current one highlighted. --}}
        @php
            $steps = $order->order_type === 'pickup'
                ? ['pending' => $t('shop_order.step.placed'), 'accepted' => $t('shop_order.step.accepted'), 'preparing' => $t('shop_order.step.packing'), 'ready' => $t('shop_order.step.ready_collect'), 'completed' => $t('shop_order.step.collected')]
                : ['pending' => $t('shop_order.step.placed'), 'accepted' => $t('shop_order.step.accepted'), 'preparing' => $t('shop_order.step.packing'), 'ready' => $t('shop_order.step.with_rider'), 'completed' => $t('shop_order.step.delivered')];
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
            <p class="mt-5 rounded-2xl bg-red-50 p-4 text-sm text-red-800">{{ $t('shop_order.cancelled') }}@if ($order->cancellation_note) {{ $t('shop_order.cancel_reason', ['reason' => $order->cancellation_note]) }}@endif
                @if (in_array($order->payment_status, ['refunded', 'partially_refunded'], true)) {{ $t('shop_order.refund_issued') }} @endif</p>
        @endif

        @if ($order->isAwaitingOnlinePayment())
            <div class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">{{ $t('shop_order.unpaid') }}</p>
                <p class="mt-0.5">{{ $t('shop_order.unpaid_note', ['minutes' => \App\Support\MarketplaceSettings::get('marketplace.unpaid_expiry_minutes')]) }}</p>
                <button type="button" wire:click="payNow" class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 font-bold text-white">{{ $t('shop_order.pay_amount', ['amount' => $currencySymbol.number_format((float) $order->total_amount, 2)]) }}</button>
            </div>
        @endif

        @if ($showCode)
            <div class="mt-4 rounded-2xl bg-slate-900 p-5 text-center text-white">
                <p class="text-xs uppercase tracking-widest text-slate-300">{{ $order->order_type === 'pickup' ? $t('shop_order.code_at_shop') : $t('shop_order.code_to_rider') }}</p>
                <p class="mt-1 text-4xl font-bold tracking-[0.3em] tabular-nums">{{ $order->delivery_otp }}</p>
                @if ($order->order_type === 'delivery' && $order->assignedWorker)
                    <p class="mt-2 text-sm text-slate-300">{{ $t('shop_order.rider', ['name' => $order->assignedWorker->user?->name]) }}</p>
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
                <div class="flex justify-between"><dt>{{ $t('shop_order.items') }}</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $order->subtotal, 2) }}</dd></div>
                @if ((float) $order->coupon_discount_amount > 0)
                    <div class="flex justify-between text-emerald-700"><dt>{{ $t('shop_order.coupon_line', ['code' => $order->coupon_code]) }}</dt><dd class="tabular-nums">−{{ $currencySymbol }}{{ number_format((float) $order->coupon_discount_amount, 2) }}</dd></div>
                @endif
                @if ($order->order_type === 'delivery')
                    <div class="flex justify-between"><dt>{{ $t('shop_order.delivery') }}</dt><dd class="tabular-nums">{{ (float) $order->delivery_fee > 0 ? $currencySymbol.number_format((float) $order->delivery_fee, 2) : $t('shop_order.free') }}</dd></div>
                @endif
                @if ((float) $order->tax_amount > 0)
                    <div class="flex justify-between"><dt>{{ $t('shop_order.tax') }}</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $order->tax_amount, 2) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold"><dt>{{ $t('shop_order.total') }}</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) $order->total_amount, 2) }}</dd></div>
                <div class="flex justify-between text-slate-500"><dt>{{ $t('shop_order.paid_by') }}</dt><dd>{{ in_array($order->payment_method, ['online', 'wallet', 'cash'], true) ? $t('shop_order.paid.'.$order->payment_method) : $order->payment_method }}</dd></div>
            </dl>
            @if ($order->order_type === 'delivery' && $order->deliveryAddress)
                <p class="mt-3 text-slate-600">{{ $t('shop_order.to', ['address' => $order->deliveryAddress->address_line]) }}</p>
                @if ($order->recipient_name)<p class="text-slate-600" data-testid="order-receiver">{{ $t('shop_order.receiver', ['name' => $order->recipient_name, 'phone' => $order->recipient_phone]) }}</p>@endif
            @endif
            @if ($order->status === 'completed')
                <a href="{{ route('customer.shop.orders.invoice', $order) }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-semibold text-blue-700">{{ $t('shop_order.invoice') }}</a>
            @endif
        </section>

        @if ($canCancel)
            <div class="mt-4">
                @if ($confirmingCancel)
                    <form wire:submit="cancel" class="space-y-2 rounded-2xl border border-red-200 bg-red-50 p-4">
                        <label for="cancel-reason" class="block text-sm font-medium text-red-900">{{ $t('shop_order.why_cancel') }}</label>
                        <input id="cancel-reason" type="text" wire:model="cancelReason" class="min-h-11 w-full rounded-xl border border-red-300 px-3 text-base">
                        @error('cancelReason') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <p class="text-xs text-red-800">{{ $order->status === 'pending' ? $t('shop_order.cancel_free') : $t('shop_order.cancel_fee') }}</p>
                        <div class="flex gap-2">
                            <button type="submit" class="min-h-11 rounded-xl bg-red-600 px-4 text-sm font-bold text-white">{{ $t('shop_order.cancel_btn') }}</button>
                            <button type="button" wire:click="$set('confirmingCancel', false)" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm">{{ $t('shop_order.keep') }}</button>
                        </div>
                    </form>
                @else
                    <button type="button" wire:click="$set('confirmingCancel', true)" class="text-sm font-medium text-red-700 hover:underline">{{ $t('shop_order.cancel_link') }}</button>
                @endif
            </div>
        @endif

        @if ($order->status === 'completed')
            <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                @if ($review)
                    <p class="text-sm">{{ $t('shop_order.you_rated', ['shop' => $order->store->name]) }} <span class="font-bold text-amber-600">{{ str_repeat('★', $review->rating) }}</span></p>
                @else
                    <form wire:submit="rate">
                        <h2 class="font-semibold">{{ $t('shop_order.how_was', ['shop' => $order->store->name]) }}</h2>
                        <div class="mt-2 flex gap-1" role="radiogroup" aria-label="{{ $t('shop_order.rating') }}">
                            @for ($s = 1; $s <= 5; $s++)
                                <button type="button" wire:click="$set('rating', {{ $s }})" role="radio" aria-checked="{{ $rating === $s ? 'true' : 'false' }}" aria-label="{{ $t('shop_order.stars', ['count' => $s]) }}"
                                        @class(['text-3xl', 'text-amber-500' => $rating >= $s, 'text-slate-300' => $rating < $s])>★</button>
                            @endfor
                        </div>
                        @error('rating') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <textarea wire:model="comment" rows="2" placeholder="{{ $t('shop_order.comment_placeholder') }}" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-base"></textarea>
                        <button type="submit" class="mt-2 min-h-11 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white">{{ $t('shop_order.send_rating') }}</button>
                    </form>
                @endif
            </section>

            {{-- E-commerce Phase 3b: returns — damaged or wrong item, within the return window. --}}
            <section class="mt-4 rounded-3xl border border-slate-200 bg-white p-4 text-sm sm:p-5">
                @if ($returnRow)
                    @php
                        $returnLabel = $returnRow->status === 'refunded'
                            ? $t($returnRow->refund_destination === 'original' ? 'shop_order.return.refunded_original' : 'shop_order.return.refunded_wallet', ['amount' => $currencySymbol.number_format((float) $returnRow->refund_amount, 2)])
                            : (in_array($returnRow->status, ['requested', 'accepted', 'declined', 'received'], true) ? $t('shop_order.return.'.$returnRow->status) : $returnRow->status);
                    @endphp
                    <p class="font-semibold">{{ $returnLabel }}</p>
                    @if ($returnRow->decision_note)<p class="mt-1 text-slate-600">{{ $returnRow->decision_note }}</p>@endif
                    @if ($returnRow->status === 'accepted' && $returnRow->pickup_status === 'open')
                        <p class="mt-2 text-slate-700">{{ $t('shop_order.return.rider_coming') }}</p>
                    @elseif ($returnRow->status === 'accepted' && $returnRow->pickup_status === 'assigned')
                        <div class="mt-2 rounded-xl bg-blue-50 p-3 text-blue-900">
                            <p>{{ $t('shop_order.return.rider_named', ['name' => $returnRow->pickupWorker?->user?->name ?? $t('shop_order.return.our_rider')]) }}</p>
                            <p class="mt-1 text-2xl font-bold tracking-widest">{{ $returnRow->pickup_otp }}</p>
                        </div>
                    @elseif ($returnRow->status === 'accepted' && $returnRow->pickup_status === 'picked')
                        <p class="mt-2 text-slate-700">{{ $t('shop_order.return.rider_has_it') }}</p>
                    @endif
                @endif

                @if ($canReturn)
                    @if (! $returning)
                        <p class="text-slate-600">{{ $t('shop_order.return.ask_line', ['days' => $returnDays]) }}</p>
                        <button type="button" wire:click="$set('returning', true)" class="mt-2 text-sm font-semibold text-blue-700 hover:underline">{{ $t('shop_order.return.link') }}</button>
                    @else
                        <form wire:submit="requestReturn" class="space-y-3">
                            <h2 class="font-semibold">{{ $t('shop_order.return.link') }}</h2>
                            <div class="flex gap-2">
                                @foreach (\App\Models\MarketplaceReturn::REASONS as $value => $label)
                                    <label @class(['cursor-pointer rounded-xl border px-3 py-2', 'border-blue-600 bg-blue-50' => $returnReason === $value, 'border-slate-300' => $returnReason !== $value])>
                                        <input type="radio" wire:model.live="returnReason" value="{{ $value }}" class="sr-only"> {{ $t('shop_order.return.reason.'.$value) }}
                                    </label>
                                @endforeach
                            </div>
                            <ul class="space-y-1">
                                @foreach ($order->items as $item)
                                    <li class="flex items-center justify-between gap-3">
                                        <span>{{ $item->product_name_snapshot }} <span class="text-slate-500">{{ $t('shop_order.return.ordered', ['count' => $item->quantity]) }}</span></span>
                                        <input type="number" min="0" max="{{ $item->quantity }}" wire:model="returnQty.{{ $item->id }}" placeholder="0" aria-label="{{ $t('shop_order.return.qty_label') }}" class="w-20 rounded-lg border border-slate-300 px-2 py-1 text-right">
                                    </li>
                                @endforeach
                            </ul>
                            <textarea wire:model="returnDescription" rows="2" placeholder="{{ $t('shop_order.return.what_wrong') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base"></textarea>
                            @error('returnDescription') <p class="text-red-700">{{ $message }}</p> @enderror
                            <label class="block"><span class="font-medium">{{ $t('shop_order.return.photos') }}</span>
                                <input type="file" wire:model="returnPhotos" multiple accept="image/jpeg,image/png,image/webp" class="mt-1 block text-sm"></label>
                            @error('returnPhotos') <p class="text-red-700">{{ $message }}</p> @enderror
                            @error('returnPhotos.*') <p class="text-red-700">{{ $message }}</p> @enderror
                            <div class="flex gap-2">
                                <button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-xl bg-blue-600 px-5 font-bold text-white">{{ $t('shop_order.return.submit') }}</button>
                                <button type="button" wire:click="$set('returning', false)" class="min-h-11 rounded-xl border border-slate-300 px-4">{{ $t('shop_order.return.cancel') }}</button>
                            </div>
                        </form>
                    @endif
                @elseif (! $returnRow)
                    <p class="text-xs text-slate-500">{{ $t('shop_order.return.rule', ['days' => $returnDays]) }}</p>
                @endif
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
