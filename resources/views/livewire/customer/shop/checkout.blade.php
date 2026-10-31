<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <a href="{{ route('customer.cart') }}" wire:navigate class="text-sm text-blue-700">{{ \App\Support\SiteText::get('cart.back') }}</a>
        <h1 class="mt-2 text-2xl font-bold tracking-tight">Checkout</h1>
        <p class="text-sm text-slate-500">From {{ $store->name }}</p>

        <form wire:submit="placeOrder" class="mt-5 space-y-4">
            <section class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                <h2 class="font-semibold">How do you want it?</h2>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    @foreach (['delivery' => ['Deliver to me', 'A 1CallFix rider brings it'], 'pickup' => ['I will pick it up', 'Show your code at the shop']] as $value => [$label, $hint])
                        <label @class(['cursor-pointer rounded-2xl border-2 p-3', 'border-blue-600 bg-blue-50' => $orderType === $value, 'border-slate-200' => $orderType !== $value])>
                            <input type="radio" wire:model.live="orderType" value="{{ $value }}" class="sr-only">
                            <span class="block text-sm font-bold">{{ $label }}</span>
                            <span class="block text-xs text-slate-600">{{ $hint }}</span>
                        </label>
                    @endforeach
                </div>

                @if ($orderType === 'delivery')
                    <div class="mt-4 space-y-2">
                        @forelse ($addresses as $a)
                            <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm', 'border-blue-600 bg-blue-50' => $addressId === $a->id, 'border-slate-200' => $addressId !== $a->id])>
                                <input type="radio" wire:model.live="addressId" value="{{ $a->id }}" class="mt-1">
                                <span><span class="font-semibold">{{ $a->label }}</span><span class="block text-slate-600">{{ $a->address_line }}@if ($a->landmark), {{ $a->landmark }}@endif</span></span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-600">You have no saved address yet.</p>
                        @endforelse
                        <a href="{{ route('customer.addresses') }}" wire:navigate class="inline-block text-sm font-medium text-blue-700">+ Add an address</a>
                    </div>
                @else
                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">Collect from <span class="font-semibold">{{ $store->name }}</span>, {{ $store->address_line }}. We'll tell you when it is packed.</p>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                <h2 class="font-semibold">Pay</h2>
                <div class="mt-3 space-y-2">
                    @if ($onlineEnabled)
                        <label @class(['flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm', 'border-blue-600 bg-blue-50' => $paymentMethod === 'online', 'border-slate-200' => $paymentMethod !== 'online'])>
                            <input type="radio" wire:model.live="paymentMethod" value="online"> <span><span class="font-semibold">UPI, card or netbanking</span><span class="block text-xs text-slate-600">Secure Razorpay checkout</span></span>
                        </label>
                    @endif
                    @if ($walletEnabled)
                        <label @class(['flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm', 'border-blue-600 bg-blue-50' => $paymentMethod === 'wallet', 'border-slate-200' => $paymentMethod !== 'wallet', 'opacity-50' => $walletBalance < $total])>
                            <input type="radio" wire:model.live="paymentMethod" value="wallet" @disabled($walletBalance < $total)>
                            <span><span class="font-semibold">Wallet</span><span class="block text-xs text-slate-600">Balance {{ $currencySymbol }}{{ number_format($walletBalance, 2) }}@if ($walletBalance < $total) — not enough for this order @endif</span></span>
                        </label>
                    @endif
                    <label @class(['flex items-center gap-3 rounded-xl border p-3 text-sm', 'cursor-pointer' => ! $cashBlocked, 'border-blue-600 bg-blue-50' => $paymentMethod === 'cash', 'border-slate-200' => $paymentMethod !== 'cash', 'opacity-50' => $cashBlocked])>
                        <input type="radio" wire:model.live="paymentMethod" value="cash" @disabled($cashBlocked)>
                        <span><span class="font-semibold">Cash {{ $orderType === 'pickup' ? 'at the shop' : 'on delivery' }}</span>
                            <span class="block text-xs text-slate-600">
                                @if ($cashBlocked) Not available: discounted items and coupons are for online payment.
                                @elseif ($freeDeliveryEarned && $orderType === 'delivery') Free delivery is for online payment; cash pays the delivery fee.
                                @else Pay when you get it. @endif
                            </span></span>
                    </label>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5">
                <label class="block text-sm">
                    <span class="font-semibold">Note for the shop <span class="font-normal text-slate-500">(optional)</span></span>
                    <input type="text" wire:model="note" maxlength="300" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base" placeholder="For example: ring the bell twice">
                </label>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-4 text-sm sm:p-5">
                <h2 class="font-semibold">Coupon</h2>
                @if ($appliedCoupon)
                    <p class="mt-2 flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2 text-emerald-800">
                        <span><span class="font-bold">{{ $appliedCoupon }}</span> · you save {{ $currencySymbol }}{{ number_format($coupon, 2) }}</span>
                        <button type="button" wire:click="removeCoupon" class="text-xs font-semibold underline">Remove</button>
                    </p>
                @else
                    <div class="mt-2 flex gap-2">
                        <label for="shop-coupon" class="sr-only">Coupon code</label>
                        <input id="shop-coupon" type="text" wire:model="couponInput" placeholder="Enter a coupon code" class="min-h-11 flex-1 rounded-xl border border-slate-300 px-3 text-base uppercase">
                        <button type="button" wire:click="applyCoupon" class="min-h-11 rounded-xl border-2 border-blue-600 px-4 text-sm font-bold text-blue-700">Apply</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Coupons work with online and wallet payment.</p>
                @endif
                @if ($couponMessage && ! $appliedCoupon)<p class="mt-1 text-sm text-red-700">{{ $couponMessage }}</p>@endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-4 text-sm sm:p-5">
                <h2 class="font-semibold">Summary</h2>
                <ul class="mt-2 divide-y divide-slate-100">
                    @foreach ($lines as $l)
                        <li class="flex justify-between gap-3 py-1.5"><span>{{ $l->quantity }} × {{ $l->product?->name }}</span>
                            <span class="tabular-nums">{{ $currencySymbol }}{{ number_format((float) ($l->variant ? $l->variant->effectivePrice() : ($l->product?->effective_price ?? 0)) * $l->quantity, 2) }}</span></li>
                    @endforeach
                </ul>
                <dl class="mt-2 space-y-1 border-t border-slate-100 pt-2">
                    <div class="flex justify-between"><dt>Items</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format($subtotal, 2) }}</dd></div>
                    @if ($coupon > 0)
                        <div class="flex justify-between text-emerald-700"><dt>Coupon {{ $appliedCoupon }}</dt><dd class="tabular-nums">−{{ $currencySymbol }}{{ number_format($coupon, 2) }}</dd></div>
                    @endif
                    @if ($orderType === 'delivery')
                        <div class="flex justify-between"><dt>Delivery</dt><dd class="tabular-nums">{{ $deliveryFee > 0 ? $currencySymbol.number_format($deliveryFee, 2) : 'Free' }}</dd></div>
                    @endif
                    @if ($tax > 0)
                        <div class="flex justify-between"><dt>Tax</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format($tax, 2) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold"><dt>To pay</dt><dd class="tabular-nums">{{ $currencySymbol }}{{ number_format($total, 2) }}</dd></div>
                </dl>
            </section>

            @if ($error)
                <p role="alert" class="rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-800">{{ $error }}</p>
            @endif

            <button type="submit" wire:loading.attr="disabled" class="min-h-12 w-full rounded-2xl bg-blue-600 text-base font-bold text-white hover:bg-blue-700 disabled:opacity-60">
                <span wire:loading.remove wire:target="placeOrder">{{ $paymentMethod === 'cash' ? 'Place order' : 'Pay '.$currencySymbol.number_format($total, 2) }}</span>
                <span wire:loading wire:target="placeOrder">Placing your order…</span>
            </button>
        </form>
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
                key: o.razorpay_key_id,
                order_id: o.razorpay_order_id,
                amount: o.amount,
                currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                description: @js('Order from '.$store->name),
                handler: () => { window.location = redirect; },
                modal: { ondismiss: () => { window.location = redirect; } },
            }).open();
        });
    </script>
    @endscript
</div>
