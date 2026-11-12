<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); $hotel = $r->accommodation; ?>
<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6" @if ($poll) wire:poll.5s @endif>
    <p class="text-xs font-semibold uppercase text-slate-500">{{ $t('hotel.booking.kicker', ['code' => $r->code]) }}</p>
    <h1 class="text-2xl font-bold tracking-tight">{{ $hotel->name }}</h1>

    <div @class(['mt-4 rounded-2xl border p-4 text-sm',
                 'border-emerald-200 bg-emerald-50 text-emerald-900' => in_array($state, ['confirmed', 'checked_in', 'checked_out', 'completed'], true),
                 'border-blue-200 bg-blue-50 text-blue-900' => in_array($state, ['confirming', 'waiting_hotel'], true),
                 'border-amber-200 bg-amber-50 text-amber-900' => $state === 'unpaid',
                 'border-slate-200 bg-slate-50 text-slate-700' => in_array($state, ['expired', 'cancelled', 'pending'], true)])
         role="status" data-testid="hotel-booking-state" data-state="{{ $state }}">
        <p class="text-base font-semibold">{{ $t('hotel.booking.state.'.$state) }}</p>
        @if ($state === 'unpaid')
            <p class="mt-1">{{ $t('hotel.booking.hold', ['minutes' => $minutesLeft]) }}</p>
            <button type="button" wire:click="pay" wire:loading.attr="disabled" wire:target="pay"
                    class="mt-3 flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700">
                {{ $t('hotel.book.pay', ['amount' => $money($r->amountDue())]) }}
            </button>
        @elseif ($state === 'expired')
            <p class="mt-1">{{ $t('hotel.booking.expired_hint') }}</p>
        @endif
    </div>

    @if ($error)
        <div role="alert" class="mt-3 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $error }}</div>
    @endif

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        @foreach ($r->rooms as $line)
            <p class="font-semibold">{{ $line->room_count }} × {{ $line->roomType?->name }} · {{ $t('hotel.meal.'.($line->ratePlan?->meal_plan ?? 'room_only')) }}</p>
        @endforeach
        <p class="mt-1 text-slate-700">{{ $r->check_in_date->format('D, j M Y') }} → {{ $r->check_out_date->format('D, j M Y') }} · {{ $r->number_of_nights }} {{ \Illuminate\Support\Str::plural('night', $r->number_of_nights) }} · {{ $r->number_of_adults }} {{ $t('hotel.search.guests') }}</p>
        @if ($primary = $r->guests->firstWhere('is_primary', true) ?? $r->guests->first())
            <p class="mt-1 text-slate-700">{{ $t('hotel.book.guest') }}: {{ $primary->name }}@if ($primary->phone) · {{ $primary->phone }}@endif</p>
        @endif
        @if ($r->guest_gst_number) <p class="mt-1 text-slate-700">GST: {{ $r->guest_gst_number }} · {{ $r->guest_business_name }}</p> @endif
        <p class="mt-1 text-slate-700">{{ $hotel->address_line }} · <a href="https://www.google.com/maps/search/?api=1&query={{ $hotel->lat }},{{ $hotel->lng }}" target="_blank" rel="noopener" class="font-medium text-blue-600 underline">{{ $t('hotel.show.directions') }}</a></p>
        @if ($hotel->contact_phone) <p class="mt-1"><a href="tel:{{ $hotel->contact_phone }}" class="font-medium text-blue-600 underline">{{ $hotel->contact_phone }}</a></p> @endif

        <dl class="mt-3 space-y-1 border-t border-slate-100 pt-3">
            <div class="flex justify-between"><dt>{{ $t('hotel.show.room_price') }}</dt><dd>{{ $money((float) $r->price_quoted) }}</dd></div>
            @if ((float) $r->tax_amount > 0) <div class="flex justify-between"><dt>{{ $t('hotel.show.gst') }}</dt><dd>{{ $money((float) $r->tax_amount) }}</dd></div> @endif
            @if ((float) $r->convenience_fee > 0) <div class="flex justify-between"><dt>{{ $t('hotel.show.fee') }}</dt><dd>{{ $money((float) $r->convenience_fee) }}</dd></div> @endif
            <div class="flex justify-between border-t border-slate-200 pt-1 font-bold"><dt>{{ $t('hotel.show.total') }}</dt><dd>{{ $money($r->amountDue()) }}</dd></div>
        </dl>
    </section>

    @if ($state === 'unpaid')
        @assets
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        @endassets
    @endif
    @script
    <script>
        $wire.on('razorpay-open', (e) => {
            const d = e.order ? e : (e[0] ?? {});
            const o = d.order;
            if (! o || ! window.Razorpay) return;
            new window.Razorpay({
                key: o.razorpay_key_id, order_id: o.razorpay_order_id, amount: o.amount, currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                prefill: @js(\App\Support\RazorpayPrefill::forCurrentUser()),
                description: @js($hotel->name),
                handler: () => { window.location.href = d.done; },
            }).open();
        });
    </script>
    @endscript
</div>
