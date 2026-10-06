@props([
    'coupon',
    'currencySymbol' => '₹',
])

{{--
    The coupon field (C3). `$coupon` is HasCouponEntry::couponView(): the screen holds only the typed code; the
    full price, discount and payable below are all computed on the server. Hidden entirely when this surface is off
    or coupons are not available.
--}}
@if ($coupon['enabled'])
    <div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 p-4']) }}>
        <label for="coupon-code" class="block text-sm font-semibold text-slate-900">Coupon code</label>
        <div class="mt-2 flex gap-2">
            <input id="coupon-code" type="text" wire:model="couponCode" wire:keydown.enter.prevent="applyCoupon" autocomplete="off" maxlength="50"
                   class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase text-slate-900 focus:border-blue-500 focus:outline focus:outline-2 focus:outline-blue-600">
            <button type="button" wire:click="applyCoupon" wire:loading.attr="disabled"
                    class="min-h-11 shrink-0 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-50">Apply</button>
        </div>

        @if ($coupon['message'] !== '')
            <p class="mt-2 text-sm text-rose-600">{{ $coupon['message'] }}</p>
        @endif

        @if ($coupon['quote'] && $coupon['quote']['eligible'])
            @php $q = $coupon['quote']; @endphp
            <dl class="mt-3 space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-slate-600">Full price</dt><dd>{{ $currencySymbol }}{{ number_format($q['full_price'], 2) }}</dd></div>
                @if ($q['subtotal'] != $q['full_price'])
                    <div class="flex justify-between"><dt class="text-slate-600">Offer price</dt><dd>{{ $currencySymbol }}{{ number_format($q['subtotal'], 2) }}</dd></div>
                @endif
                <div class="flex justify-between text-emerald-700"><dt>Discount</dt><dd>−{{ $currencySymbol }}{{ number_format($q['discount'], 2) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold text-slate-900"><dt>You pay</dt><dd>{{ $currencySymbol }}{{ number_format($q['payable'], 2) }}</dd></div>
            </dl>
        @endif

        <p class="mt-3 text-[11px] leading-snug text-slate-500">{{ \App\Services\Coupons\CouponQuoteService::VISIT_CHARGE_NOTE }}</p>
    </div>
@endif
