<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $inp = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600';
?>
<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight">{{ $t('hotel.book.title') }}</h1>

    {{-- ── the stay ── --}}
    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
        <p class="text-lg font-semibold">{{ $hotel->name }}</p>
        <p class="text-sm text-slate-600">{{ $room->name }} · {{ $t('hotel.meal.'.$plan->meal_plan) }}</p>
        <p class="mt-2 text-sm text-slate-800">
            {{ \Illuminate\Support\Carbon::parse($checkIn)->format('D, j M') }} → {{ \Illuminate\Support\Carbon::parse($checkOut)->format('D, j M') }}
            · {{ $t('hotel.show.stay', ['rooms' => \App\Support\SiteText::count('hotel.rooms', $rooms), 'nights' => \App\Support\SiteText::count('hotel.nights', $nights)]) }} · @if ($children > 0) {{ $t('hotel.book.guests_line', ['adults' => $adults, 'children' => $children]) }} @else {{ $adults }} {{ $t('hotel.search.guests') }} @endif
        </p>
        <p @class(['mt-1 text-sm font-medium', 'text-amber-700' => $nr, 'text-emerald-700' => ! $nr])>{{ $cancel }}</p>
        @if ($replacing) <p class="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900" data-testid="hotel-replacing">{{ $t('hotel.book.replacing', ['code' => $replacing->code]) }}</p> @endif

        <dl class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm" data-testid="hotel-book-price">
            <div class="flex justify-between"><dt>{{ $t('hotel.show.room_price') }}</dt><dd>{{ $money($quote['room_total']) }}</dd></div>
            @if (($quote['discount'] ?? 0) > 0) <div class="flex justify-between text-emerald-700" data-testid="hotel-book-discount"><dt>{{ $t('hotel.show.discount', ['code' => $appliedCoupon]) }}</dt><dd>−{{ $money($quote['discount']) }}</dd></div> @endif
            @if ($quote['gst'] > 0) <div class="flex justify-between"><dt>{{ $t('hotel.show.gst') }}</dt><dd>{{ $money($quote['gst']) }}</dd></div> @endif
            @if ($quote['fee'] > 0) <div class="flex justify-between"><dt>{{ $t('hotel.show.fee') }}</dt><dd>{{ $money($quote['fee']) }}</dd></div> @endif
            <div class="flex justify-between border-t border-slate-200 pt-1 text-base font-bold"><dt>{{ $t('hotel.show.total') }}</dt><dd>{{ $money($quote['total']) }}</dd></div>
        </dl>
    </section>

    @if ($error)
        <div role="alert" class="mt-4 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $error }}</div>
    @endif

    @if (! $canBook)
        <div role="alert" class="mt-4 rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-900">{{ $quote['taxes_known'] ? $t('hotel.card.full') : $t('hotel.show.booking_soon') }}</div>
    @else
        {{-- ── guest ── --}}
        <section class="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $t('hotel.book.guest') }}</h2>
            <label class="block text-sm font-medium text-slate-800">{{ $t('hotel.book.guest_name') }}
                <input type="text" wire:model="guestName" autocomplete="name" class="{{ $inp }}">
            </label>
            @error('guestName') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            <label class="block text-sm font-medium text-slate-800">{{ $t('hotel.book.guest_phone') }}
                <input type="tel" wire:model="guestPhone" autocomplete="tel" class="{{ $inp }}">
            </label>
            @error('guestPhone') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            <label class="block text-sm font-medium text-slate-800">{{ $t('hotel.book.requests') }}
                <textarea wire:model="requests" rows="2" maxlength="500" class="{{ $inp }} py-2" placeholder="{{ $t('hotel.book.requests_hint') }}"></textarea>
            </label>
            <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-slate-800">
                <input type="checkbox" wire:model.live="business" class="h-5 w-5"> {{ $t('hotel.book.business') }}
            </label>
            @if ($business)
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-medium text-slate-800">{{ $t('hotel.book.gst_number') }}
                        <input type="text" wire:model="gstNumber" maxlength="15" class="{{ $inp }} uppercase">
                    </label>
                    <label class="block text-sm font-medium text-slate-800">{{ $t('hotel.book.business_name') }}
                        <input type="text" wire:model="businessName" maxlength="120" class="{{ $inp }}">
                    </label>
                </div>
                @error('gstNumber') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                @error('businessName') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @endif
        </section>

        {{-- ── coupon (online / wallet only) ── --}}
        @if ($couponsOn)
            <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4" data-testid="hotel-coupon">
                <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $t('hotel.book.coupon') }}</h2>
                @if ($appliedCoupon !== '')
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold text-emerald-700">✓ {{ $t('hotel.book.coupon_applied', ['code' => $appliedCoupon]) }}</p>
                        <button type="button" wire:click="removeCoupon" class="min-h-11 px-2 text-sm font-medium text-slate-600 underline">{{ $t('hotel.book.coupon_remove') }}</button>
                    </div>
                @else
                    <form wire:submit="applyCoupon" class="mt-2 flex gap-2">
                        <input type="text" wire:model="couponInput" maxlength="50" placeholder="{{ $t('hotel.book.coupon_placeholder') }}" aria-label="{{ $t('hotel.book.coupon_placeholder') }}" class="{{ $inp }} mt-0 uppercase">
                        <button type="submit" class="min-h-11 shrink-0 rounded-xl border border-blue-600 px-4 text-sm font-semibold text-blue-700 hover:bg-blue-50">{{ $t('hotel.book.coupon_apply') }}</button>
                    </form>
                    @if ($couponMessage) <p role="alert" class="mt-2 text-sm text-red-700">{{ $couponMessage }}</p> @endif
                @endif
                <p class="mt-2 text-xs text-slate-500">{{ $t('hotel.book.coupon_online') }}</p>
            </section>
        @endif

        {{-- ── pay ── --}}
        <div class="mt-4 space-y-2">
            <button type="button" wire:click="pay('online')" wire:loading.attr="disabled" wire:target="pay"
                    class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 text-base font-semibold text-white hover:bg-blue-700 disabled:opacity-60" data-testid="hotel-pay-online">
                {{ $t('hotel.book.pay', ['amount' => $money($quote['total'])]) }}
            </button>
            @if ($walletOn)
                <button type="button" wire:click="pay('wallet')" wire:loading.attr="disabled" wire:target="pay"
                        class="flex min-h-12 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-base font-semibold text-slate-800 hover:bg-slate-50" data-testid="hotel-pay-wallet">
                    {{ $t('hotel.book.pay_wallet', ['balance' => $balance]) }}
                </button>
            @endif
            <p class="text-center text-xs text-slate-500">{{ $t('hotel.book.secure') }}</p>
        </div>
    @endif

    @assets
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    @endassets
    @script
    <script>
        $wire.on('razorpay-open', (e) => {
            const d = e.order ? e : (e[0] ?? {});
            const o = d.order;
            if (! o || ! window.Razorpay) { if (d.back) window.location.href = d.back; return; }
            new window.Razorpay({
                key: o.razorpay_key_id,
                order_id: o.razorpay_order_id,
                amount: o.amount,
                currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                prefill: @js(\App\Support\RazorpayPrefill::forCurrentUser()),
                description: @js($hotel->name),
                // Paid: the booking page waits for the bank's confirmation. Closed without paying: the booking page,
                // where Pay stays open until the rooms' hold ends.
                handler: () => { window.location.href = d.done; },
                modal: { ondismiss: () => { window.location.href = d.back; } },
            }).open();
        });
    </script>
    @endscript
</div>
