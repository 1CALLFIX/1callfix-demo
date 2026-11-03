@php
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $money = fn (float $v) => $currencySymbol.number_format($v, 2);
    $itemCount = (int) $lines->sum('quantity');
    $saving = round($price['discount'] + $price['coupon'] + $price['deliveryWaived'], 2);
@endphp
{{-- Shop Phase A 2026-11-02: checkout redrawn after walking Amazon, Flipkart and Meesho end to end. One page (faster
     than their 3-4 steps for a local order) in three numbered blocks — 1 Delivery, 2 Your items, 3 Payment — and a
     price box like Flipkart's (price, offer, coupon, delivery, total, "you save"). Each way to pay shows its own
     amount (Meesho): online/wallet get the offer and free delivery; cash pays the full price (owner rule: benefits are
     online-only). CreateMarketplaceOrderAction decides every rupee; this page only previews it. --}}
<div class="mb-bottom-nav bg-slate-50/60">
    <div class="mx-auto max-w-5xl px-4 pb-28 pt-6 sm:px-6 lg:pb-6">
        <a href="{{ route('customer.cart') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-blue-700"><x-icon name="arrow-left" class="h-4 w-4" /> {{ $t('cart.back') }}</a>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $t('shop_checkout.title') }}</h1>
                <p class="text-sm text-slate-500">{{ $t('shop_checkout.from', ['shop' => $store->name]) }}</p>
            </div>
            <p class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800"><x-icon name="shield" class="h-4 w-4" /> {{ $t('shop_checkout.online_hint') }}</p>
        </div>

        <form wire:submit="placeOrder" class="mt-5 grid gap-5 lg:grid-cols-[1fr_22rem] lg:items-start">
            <div class="space-y-4">
                {{-- 1. Delivery --}}
                <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <h2 class="flex items-center gap-2 font-bold text-slate-900"><span class="grid h-6 w-6 place-items-center rounded-full bg-blue-600 text-xs text-white">1</span> {{ $t('shop_checkout.step_address') }}</h2>
                    <div class="mt-3 grid grid-cols-2 gap-1 rounded-2xl bg-slate-100 p-1" role="radiogroup" aria-label="{{ $t('shop_checkout.how') }}">
                        @foreach (['delivery' => [$t('shop_checkout.deliver'), $t('shop_checkout.deliver_hint')], 'pickup' => [$t('shop_checkout.pickup'), $isFood ? $t('food.checkout.pickup_hint') : $t('shop_checkout.pickup_hint')]] as $value => [$label, $hint])
                            <label @class(['cursor-pointer rounded-xl px-3 py-2 text-center transition', 'bg-white shadow-sm ring-1 ring-blue-600' => $orderType === $value, 'text-slate-600' => $orderType !== $value])>
                                <input type="radio" wire:model.live="orderType" value="{{ $value }}" class="sr-only">
                                <span class="block text-sm font-bold">{{ $label }}</span>
                                <span class="block text-[11px] text-slate-500">{{ $hint }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($orderType === 'delivery')
                        <p class="mt-3 flex items-start gap-2 text-xs text-slate-500" data-testid="checkout-from-shop"><x-icon name="building" class="mt-0.5 h-4 w-4 shrink-0" /> {{ $t('shop_checkout.from_shop', ['shop' => $store->name, 'address' => $store->address_line]) }}</p>
                        <div class="mt-3 space-y-2" data-testid="checkout-addresses">
                            @forelse ($addresses as $a)
                                @php $on = (int) $addressId === $a->id; @endphp
                                <label wire:key="addr-{{ $a->id }}" @class(['flex cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 text-sm transition', 'border-blue-600 bg-blue-50/60' => $on, 'border-slate-200 hover:border-slate-300' => ! $on])>
                                    <input type="radio" wire:model.live="addressId" value="{{ $a->id }}" class="mt-1 accent-blue-600">
                                    {{-- 2026-11-02 (Amazon / Flipkart / Meesho all show who receives it): name, address with
                                         pincode, mobile number. --}}
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2"><span class="font-bold text-slate-900" data-testid="checkout-receiver">{{ $customer->name }}</span><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold uppercase text-slate-600">{{ $a->label }}</span></span>
                                        <span class="block text-slate-600">{{ $a->address_line }}@if ($a->landmark), {{ $a->landmark }}@endif @if ($a->city), {{ $a->city }}@endif @if ($a->pincode) - {{ $a->pincode }}@endif</span>
                                        @if ($customer->phone)<span class="block text-slate-600">{{ $customer->phone }}</span>@endif
                                    </span>
                                </label>
                            @empty
                                @unless ($addingAddress)<p class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900">{{ $t('shop_checkout.no_address') }}</p>@endunless
                            @endforelse

                            @if ($addingAddress)
                                <div class="rounded-2xl border-2 border-dashed border-blue-300 bg-blue-50/40 p-3" data-testid="checkout-new-address">
                                    <p class="text-sm font-bold">{{ $t('shop_checkout.new_address') }}</p>
                                    <button type="button" data-locate-address class="mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 hover:bg-slate-50">
                                        <x-icon name="map-pin" class="h-4 w-4 text-blue-600" />
                                        <span data-locate-address-label>{{ $t('shop_checkout.use_location') }}</span>
                                    </button>
                                    @if ($locatedZoneName !== '')<p class="mt-1 text-xs text-emerald-700">{{ $t('shop_checkout.located', ['area' => $locatedZoneName]) }}</p>@endif
                                    @if ($addressError)<p class="mt-1 text-xs text-red-700">{{ $addressError }}</p>@endif
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div>
                                            <input type="text" wire:model="newAddress.label" maxlength="40" placeholder="{{ $t('shop_checkout.address_label') }}" aria-label="{{ $t('shop_checkout.address_label') }}" class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                                            @error('newAddress.label')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                        </div>
                                        <input type="text" wire:model="newAddress.pincode" maxlength="12" inputmode="numeric" placeholder="{{ $t('shop_checkout.address_pincode') }}" aria-label="{{ $t('shop_checkout.address_pincode') }}" class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                                        <div class="sm:col-span-2">
                                            <input type="text" wire:model="newAddress.address_line" maxlength="255" placeholder="{{ $t('shop_checkout.address_line') }}" aria-label="{{ $t('shop_checkout.address_line') }}" class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base">
                                            @error('newAddress.address_line')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                        </div>
                                        <input type="text" wire:model="newAddress.landmark" maxlength="120" placeholder="{{ $t('shop_checkout.address_landmark') }}" aria-label="{{ $t('shop_checkout.address_landmark') }}" class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base sm:col-span-2">
                                    </div>
                                    <div class="mt-2 flex gap-2">
                                        <button type="button" wire:click="saveAddress" class="min-h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700">{{ $t('shop_checkout.save_address') }}</button>
                                        <button type="button" wire:click="cancelAddAddress" class="min-h-11 rounded-xl px-4 text-sm text-slate-600 hover:bg-slate-100">{{ $t('shop_checkout.cancel') }}</button>
                                    </div>
                                </div>
                            @else
                                <button type="button" wire:click="startAddAddress" class="inline-flex min-h-10 items-center gap-1 text-sm font-semibold text-blue-700"><x-icon name="plus" class="h-4 w-4" /> {{ ltrim($t('shop_checkout.add_address'), '+ ') }}</button>
                            @endif
                        </div>

                        <div class="mt-3 border-t border-slate-100 pt-3">
                            <label class="flex cursor-pointer items-start gap-3 text-sm">
                                <input type="checkbox" wire:model.live="sendToOther" class="mt-1 h-4 w-4 accent-blue-600" data-testid="checkout-send-to-other">
                                <span><span class="font-semibold">{{ $t('shop_checkout.someone_else') }}</span><span class="block text-xs text-slate-600">{{ $t('shop_checkout.someone_else_hint') }}</span></span>
                            </label>
                            @if ($sendToOther)
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <input type="text" wire:model="recipientName" maxlength="100" autocomplete="off" placeholder="{{ $t('shop_checkout.recipient_name') }}" aria-label="{{ $t('shop_checkout.recipient_name') }}" class="block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base">
                                        @error('recipientName')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <input type="tel" wire:model="recipientPhone" maxlength="16" inputmode="tel" autocomplete="off" placeholder="{{ $t('shop_checkout.recipient_phone') }}" aria-label="{{ $t('shop_checkout.recipient_phone') }}" class="block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base">
                                        @error('recipientPhone')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="mt-3 flex items-start gap-2 rounded-2xl bg-slate-50 p-3 text-sm text-slate-700"><x-icon name="building" class="mt-0.5 h-4 w-4 shrink-0 text-blue-600" /> {{ $t('shop_checkout.collect_from', ['shop' => $store->name, 'address' => $store->address_line]) }}</p>
                    @endif
                </section>

                {{-- 2. Your items --}}
                <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <h2 class="flex items-center gap-2 font-bold text-slate-900"><span class="grid h-6 w-6 place-items-center rounded-full bg-blue-600 text-xs text-white">2</span> {{ $t('shop_checkout.step_items', ['count' => $itemCount]) }}</h2>
                    @if ($deliveryPromise)
                        {{-- "Arriving by" next to the items, as on Amazon, Flipkart and Meesho. --}}
                        <p class="mt-3 flex items-center gap-1.5 rounded-xl bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-900" data-testid="checkout-promise"><x-icon name="clock" class="h-4 w-4 text-blue-600" /> {{ $deliveryPromise }}</p>
                    @endif
                    <ul class="mt-2 divide-y divide-slate-100">
                        @foreach ($lines as $l)
                            @php $img = $l->variant?->imageUrl() ?? ($l->product?->image_urls[0] ?? null); @endphp
                            <li class="flex items-start gap-3 py-3" wire:key="co-line-{{ $l->id }}">
                                <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl border border-slate-100 bg-slate-50">
                                    @if ($img)<img src="{{ $img }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                    @else<span class="flex h-full w-full items-center justify-center font-bold text-slate-300">{{ mb_substr((string) $l->product?->name, 0, 1) }}</span>@endif
                                </div>
                                <div class="min-w-0 flex-1 text-sm">
                                    <p class="line-clamp-1 font-semibold text-slate-900">@if ($isFood)<x-food-mark :type="$l->product?->food_type ?: 'veg'" class="mr-1" />@endif{{ $l->product?->name }}@if ($l->variant) <span class="font-normal text-slate-500">({{ $l->variant->name }})</span>@endif</p>
                                    @if ($l->addOnModels()->isNotEmpty())<p class="line-clamp-2 text-xs text-slate-500">+ {{ $l->addOnModels()->pluck('name')->implode(', ') }}</p>@endif
                                    <p class="text-xs text-slate-500">{{ $l->quantity }} × {{ $money($l->unitPriceWithAddOns($paymentMethod === 'cash')) }}
                                        @if ($l->isDiscounted() && $paymentMethod !== 'cash')<span class="ml-1 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[11px] font-bold text-emerald-700">{{ $t('shop.card.off_online', ['percent' => rtrim(rtrim(number_format((float) $l->product->discount_percent, 1), '0'), '.')]) }}</span>@endif
                                    </p>
                                    {{-- Change the quantity or remove without going back to the cart (Flipkart / Amazon / Meesho). --}}
                                    <div class="mt-1.5 flex items-center gap-3">
                                        <div class="inline-flex items-center rounded-full border border-slate-300 bg-white">
                                            <button type="button" wire:click="changeLine({{ $l->id }}, -1)" class="flex h-8 w-8 items-center justify-center text-base font-bold text-slate-700" aria-label="{{ $t('cart.one_less') }}">−</button>
                                            <span class="min-w-5 text-center text-sm font-bold tabular-nums" data-testid="co-qty-{{ $l->id }}">{{ $l->quantity }}</span>
                                            <button type="button" wire:click="changeLine({{ $l->id }}, 1)" class="flex h-8 w-8 items-center justify-center text-base font-bold text-slate-700" aria-label="{{ $t('cart.one_more') }}">+</button>
                                        </div>
                                        <button type="button" wire:click="removeLine({{ $l->id }})" class="text-xs font-semibold text-slate-500 hover:text-red-700">{{ $t('cart.remove') }}</button>
                                    </div>
                                </div>
                                <p class="text-right text-sm font-bold tabular-nums">
                                    @if ($l->isDiscounted() && $paymentMethod !== 'cash')<span class="block text-xs font-normal text-slate-400 line-through">{{ $money(($l->listUnitPrice() + $l->addOnsTotal()) * $l->quantity) }}</span>@endif
                                    {{ $money($l->unitPriceWithAddOns($paymentMethod === 'cash') * $l->quantity) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                    <label class="mt-2 block text-sm">
                        <span class="font-semibold">{{ $isFood ? $t('food.checkout.note') : $t('shop_checkout.note') }} <span class="font-normal text-slate-500">{{ $t('shop_checkout.optional') }}</span></span>
                        <input type="text" wire:model="note" maxlength="300" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base" placeholder="{{ $t('shop_checkout.note_placeholder') }}">
                    </label>
                </section>

                {{-- 3. Payment: each way to pay with its own amount (Meesho). --}}
                <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <h2 class="flex items-center gap-2 font-bold text-slate-900"><span class="grid h-6 w-6 place-items-center rounded-full bg-blue-600 text-xs text-white">3</span> {{ $t('shop_checkout.step_pay') }}</h2>
                    <div class="mt-3 space-y-2" data-testid="payment-options">
                        @if ($onlineEnabled)
                            <label @class(['flex cursor-pointer items-center gap-3 rounded-2xl border-2 p-3 text-sm transition', 'border-blue-600 bg-blue-50/60' => $paymentMethod === 'online', 'border-slate-200 hover:border-slate-300' => $paymentMethod !== 'online'])>
                                <input type="radio" wire:model.live="paymentMethod" value="online" class="accent-blue-600">
                                <span class="min-w-0 flex-1"><span class="block font-bold">{{ $t('shop_checkout.online') }}</span><span class="block text-xs text-slate-600">{{ $t('shop_checkout.online_hint') }}</span></span>
                                <span class="text-right">
                                    <span class="block font-extrabold tabular-nums">{{ $money($online['total']) }}</span>
                                    @if ($onlineSaves > 0)<span class="block text-[11px] font-bold text-emerald-700" data-testid="online-saves">{{ $t('shop_checkout.online_saves', ['amount' => $money($onlineSaves)]) }}</span>@endif
                                </span>
                            </label>
                        @endif
                        @if ($walletEnabled)
                            @php $walletShort = $walletBalance < $online['total']; @endphp
                            <label @class(['flex items-center gap-3 rounded-2xl border-2 p-3 text-sm transition', 'cursor-pointer' => ! $walletShort, 'border-blue-600 bg-blue-50/60' => $paymentMethod === 'wallet', 'border-slate-200' => $paymentMethod !== 'wallet', 'opacity-60' => $walletShort])>
                                <input type="radio" wire:model.live="paymentMethod" value="wallet" class="accent-blue-600" @disabled($walletShort)>
                                <span class="min-w-0 flex-1"><span class="block font-bold">{{ $t('shop_checkout.wallet') }}</span><span class="block text-xs text-slate-600">{{ $t('shop_checkout.wallet_balance', ['amount' => $money($walletBalance)]) }}@if ($walletShort){{ $t('shop_checkout.wallet_short') }}@endif</span></span>
                                <span class="text-right">
                                    <span class="block font-extrabold tabular-nums">{{ $money($online['total']) }}</span>
                                    @if ($onlineSaves > 0)<span class="block text-[11px] font-bold text-emerald-700">{{ $t('shop_checkout.online_saves', ['amount' => $money($onlineSaves)]) }}</span>@endif
                                </span>
                            </label>
                        @endif
                        <label @class(['flex cursor-pointer items-center gap-3 rounded-2xl border-2 p-3 text-sm transition', 'border-blue-600 bg-blue-50/60' => $paymentMethod === 'cash', 'border-slate-200 hover:border-slate-300' => $paymentMethod !== 'cash'])>
                            <input type="radio" wire:model.live="paymentMethod" value="cash" class="accent-blue-600">
                            <span class="min-w-0 flex-1"><span class="block font-bold">{{ $orderType === 'pickup' ? $t('shop_checkout.cash_shop') : $t('shop_checkout.cash_delivery') }}</span>
                                <span class="block text-xs text-slate-600" data-testid="cash-note">{{ $onlineSaves > 0 ? $t('shop_checkout.cash_full') : $t('shop_checkout.cash_hint') }}</span></span>
                            <span class="block text-right font-extrabold tabular-nums" data-testid="cash-total">{{ $money($cashPrice['total']) }}</span>
                        </label>
                    </div>

                    {{-- Coupon (online / wallet only). --}}
                    <div class="mt-4 border-t border-slate-100 pt-3 text-sm" x-data="{ open: @js($appliedCoupon !== '' || $couponMessage !== '') }">
                        @if ($appliedCoupon)
                            <p class="flex items-center justify-between rounded-2xl bg-emerald-50 px-3 py-2 text-emerald-800">
                                <span class="flex items-center gap-1.5"><x-icon name="ticket" class="h-4 w-4" /> <span class="font-bold">{{ $appliedCoupon }}</span> · {{ $t('shop_checkout.you_save', ['amount' => $money($coupon)]) }}</span>
                                <button type="button" wire:click="removeCoupon" class="text-xs font-semibold underline">{{ $t('shop_checkout.remove') }}</button>
                            </p>
                        @else
                            <button type="button" x-on:click="open = ! open" class="inline-flex items-center gap-1.5 font-semibold text-blue-700"><x-icon name="ticket" class="h-4 w-4" /> {{ $t('shop_checkout.coupon') }}</button>
                            <div x-show="open" x-cloak class="mt-2">
                                <div class="flex gap-2">
                                    <label for="shop-coupon" class="sr-only">{{ $t('shop_checkout.coupon_label') }}</label>
                                    <input id="shop-coupon" type="text" wire:model="couponInput" placeholder="{{ $t('shop_checkout.coupon_placeholder') }}" class="min-h-11 flex-1 rounded-xl border border-slate-300 px-3 text-base uppercase">
                                    <button type="button" wire:click="applyCoupon" class="min-h-11 rounded-xl border-2 border-blue-600 px-4 text-sm font-bold text-blue-700">{{ $t('shop_checkout.apply') }}</button>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $t('shop_checkout.coupon_online') }}</p>
                            </div>
                        @endif
                        @if ($couponMessage && ! $appliedCoupon)<p class="mt-1 text-sm text-red-700">{{ $couponMessage }}</p>@endif
                    </div>
                </section>
            </div>

            {{-- Price details + pay (sticks beside the steps on a big screen). --}}
            <aside class="lg:sticky lg:top-20">
                <section id="price-details" class="scroll-mt-20 rounded-3xl border border-slate-200 bg-white p-4 text-sm shadow-sm sm:p-5" data-testid="price-details">
                    <h2 class="font-bold uppercase tracking-wide text-slate-500">{{ $t('shop_checkout.price_details') }}</h2>
                    <dl class="mt-3 space-y-2">
                        <div class="flex justify-between"><dt>{{ $t('shop_checkout.price_mrp', ['count' => $itemCount]) }}</dt><dd class="tabular-nums">{{ $money($price['list']) }}</dd></div>
                        @if ($price['discount'] > 0)
                            <div class="flex justify-between text-emerald-700"><dt>{{ $t('shop_checkout.price_discount') }}</dt><dd class="tabular-nums">−{{ $money($price['discount']) }}</dd></div>
                        @endif
                        @if ($coupon > 0)
                            <div class="flex justify-between text-emerald-700"><dt>{{ $t('shop_checkout.coupon_line', ['code' => $appliedCoupon]) }}</dt><dd class="tabular-nums">−{{ $money($coupon) }}</dd></div>
                        @endif
                        @if ($orderType === 'delivery')
                            <div class="flex justify-between"><dt>{{ $t('shop_checkout.delivery') }}</dt>
                                <dd class="tabular-nums">@if ($deliveryFee > 0){{ $money($deliveryFee) }}@else<span class="mr-1 text-slate-400 line-through">{{ $price['deliveryWaived'] > 0 ? $money($price['deliveryWaived']) : '' }}</span><span class="font-semibold text-emerald-700">{{ $t('shop_checkout.free') }}</span>@endif</dd></div>
                        @endif
                        @if ($packaging > 0)
                            <div class="flex justify-between" data-testid="checkout-packaging"><dt>{{ $t('food.checkout.packaging') }}</dt><dd class="tabular-nums">{{ $money($packaging) }}</dd></div>
                        @endif
                        @if ($tax > 0)
                            <div class="flex justify-between"><dt>{{ $isFood ? $t('food.checkout.gst') : $t('shop_checkout.tax') }}</dt><dd class="tabular-nums">{{ $money($tax) }}</dd></div>
                        @endif
                        <div class="flex items-baseline justify-between border-t border-dashed border-slate-200 pt-3 text-lg font-extrabold text-slate-900"><dt>{{ $t('shop_checkout.total') }}</dt><dd class="tabular-nums" data-testid="checkout-total">{{ $money($total) }}</dd></div>
                        <p class="text-right text-[11px] text-slate-500">{{ $t('shop_checkout.incl_taxes') }}</p>
                    </dl>
                    @if ($saving > 0)
                        <p class="mt-3 flex items-center gap-1.5 rounded-2xl bg-emerald-50 px-3 py-2 font-semibold text-emerald-800" data-testid="checkout-saving"><x-icon name="tag" class="h-4 w-4" /> {{ $t('shop_checkout.saving_banner', ['amount' => $money($saving)]) }}</p>
                    @endif

                    @if ($deliveryProblem && $orderType === 'delivery')
                        <p role="alert" class="mt-3 rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-900" data-testid="delivery-problem">{{ $deliveryProblem }}</p>
                    @endif
                    @if ($error)
                        <p role="alert" class="mt-3 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $error }}</p>
                    @endif

                    <button type="submit" wire:loading.attr="disabled" class="mt-4 min-h-12 w-full rounded-2xl bg-amber-400 text-base font-bold text-slate-900 shadow-sm hover:bg-amber-300 disabled:opacity-60" data-testid="place-order">
                        <span wire:loading.remove wire:target="placeOrder">{{ $paymentMethod === 'cash' ? $t('shop_checkout.place') : $t('shop_checkout.pay_amount', ['amount' => $money($total)]) }}</span>
                        <span wire:loading wire:target="placeOrder">{{ $t('shop_checkout.placing') }}</span>
                    </button>
                    <p class="mt-2 flex items-center justify-center gap-1 text-center text-[11px] text-slate-500"><x-icon name="shield" class="h-3.5 w-3.5" /> {{ $t('shop_checkout.no_charge_yet') }}</p>
                    {{-- As on Amazon: placing the order means agreeing to the Terms of Use and the Privacy Policy. --}}
                    @php
                        $agree = e($t('shop_checkout.agree', ['terms' => '%%TERMS%%', 'privacy' => '%%PRIVACY%%']));
                        $agree = str_replace(
                            ['%%TERMS%%', '%%PRIVACY%%'],
                            ['<a href="'.e(route('customer.terms')).'" target="_blank" class="underline">'.e($t('shop_checkout.terms')).'</a>',
                             '<a href="'.e(route('customer.privacy')).'" target="_blank" class="underline">'.e($t('shop_checkout.privacy')).'</a>'],
                            $agree);
                    @endphp
                    <p class="mt-1 text-center text-[11px] text-slate-500" data-testid="checkout-agree">{!! $agree !!}</p>
                </section>
            </aside>

            {{-- Phone: the total and the pay button stay at the bottom of the screen (Flipkart / Meesho), above the
                 bottom menu, so the customer never scrolls down to pay. Hidden on a big screen (the price box sticks). --}}
            <div class="fixed inset-x-0 bottom-16 z-30 border-t border-slate-200 bg-white/95 px-4 py-2.5 shadow-[0_-8px_24px_-12px_rgba(15,23,42,0.25)] backdrop-blur lg:hidden" data-testid="mobile-pay-bar">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-3">
                    <a href="#price-details" class="min-w-0">
                        @if ($saving > 0)<span class="block text-xs text-slate-400 line-through tabular-nums">{{ $money($total + $saving) }}</span>@endif
                        <span class="block text-lg font-extrabold tabular-nums text-slate-900">{{ $money($total) }}</span>
                        <span class="block text-[11px] font-semibold text-blue-700">{{ $t('shop_checkout.see_price_details') }}</span>
                    </a>
                    <button type="submit" wire:loading.attr="disabled" class="min-h-12 shrink-0 rounded-2xl bg-amber-400 px-6 text-base font-bold text-slate-900 shadow-sm hover:bg-amber-300 disabled:opacity-60">
                        <span wire:loading.remove wire:target="placeOrder">{{ $paymentMethod === 'cash' ? $t('shop_checkout.place') : $t('shop_checkout.pay_amount', ['amount' => $money($total)]) }}</span>
                        <span wire:loading wire:target="placeOrder">{{ $t('shop_checkout.placing') }}</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    @assets
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    @endassets
    @script
    <script>
        window.cfWireLocateButton && window.cfWireLocateButton($wire);
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
                description: @js($t('shop_checkout.razorpay_desc', ['shop' => $store->name])),
                handler: () => { window.location = redirect; },
                modal: { ondismiss: () => { window.location = redirect; } },
            }).open();
        });
    </script>
    @endscript
</div>
