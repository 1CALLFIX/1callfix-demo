<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:px-6" @if (in_array($booking->status, ['pending_payment', 'awaiting_owner'], true)) wire:poll.10s @endif>
    <a href="{{ route('customer.halls.bookings') }}" wire:navigate class="text-sm text-slate-600 hover:underline">&larr; {{ $t('hall.bookings.title') }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $t('hall.booking.title') }}</h1>
    <p class="mt-1 text-sm text-slate-600">
        @if ($hallUrl) <a href="{{ $hallUrl }}" wire:navigate class="font-medium text-slate-900 underline">{{ $hall->name }}</a> @else {{ $hall->name }} @endif
    </p>
    <p class="mt-1 font-mono text-xs text-slate-500">{{ $booking->code }}</p>

    @if ($error) <p role="alert" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $error }}</p> @endif

    @if ($booking->status === 'pending_payment')
        <div role="status" class="mt-5 space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="hall-booking-pending">
            <p>{{ $justPaid ? $t('hall.booking.confirming') : $t('hall.booking.waiting', ['time' => $holdUntil]) }}</p>
            @unless ($justPaid)
                <button type="button" wire:click="payNow" class="flex min-h-11 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700">{{ $t('hall.booking.pay_now') }} · {{ $money((float) $booking->pay_now) }}</button>
            @endunless
        </div>
    @elseif ($booking->status === 'awaiting_owner')
        <div role="status" class="mt-5 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-testid="hall-booking-awaiting">{{ $t('hall.booking.awaiting', ['time' => $acceptBy]) }}</div>
    @elseif ($booking->status === 'confirmed')
        <div role="status" class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-testid="hall-booking-confirmed">
            <p class="font-semibold">{{ $t('hall.booking.confirmed') }}</p>
            @if ($hall->contact_phone) <p class="mt-1">{{ $t('hall.booking.call_hall') }} <a href="tel:{{ $hall->contact_phone }}" class="font-semibold underline">{{ $hall->contact_phone }}</a></p> @endif
        </div>
    @elseif ($booking->status === 'declined')
        <div role="alert" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" data-testid="hall-booking-declined">{{ $t('hall.booking.declined', ['reason' => $booking->decline_reason, 'amount' => $money((float) $booking->refunded_amount)]) }}</div>
    @elseif ($booking->status === 'expired')
        <div role="alert" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">{{ $t('hall.booking.expired') }}</div>
    @endif

    <dl class="mt-5 space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        <div><dt class="font-semibold text-slate-500">{{ $t('hall.booking.when') }}</dt><dd class="mt-0.5">{{ $when }}</dd></div>
        <div><dt class="font-semibold text-slate-500">{{ $t('hall.show.where') }}</dt><dd class="mt-0.5">{{ $hall->address_line }} <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="ml-1 font-medium text-blue-700 underline">{{ $t('hall.show.directions') }}</a></dd></div>
        @if ($booking->occasion || $booking->guests)
            <div><dt class="font-semibold text-slate-500">{{ $t('hall.book.occasion') }}</dt><dd class="mt-0.5">{{ collect([$booking->occasion, $booking->guests ? $t('hall.booking.guests', ['n' => $booking->guests]) : null])->filter()->implode(' · ') }}</dd></div>
        @endif
    </dl>

    <div class="mt-4 space-y-2 rounded-2xl border border-slate-200 bg-white p-4 text-sm" data-testid="hall-booking-money">
        <div class="flex justify-between gap-3"><span>{{ $t('hall.book.price', ['slot' => $booking->slot_name]) }}</span><span>{{ $money((float) $booking->hall_price) }}</span></div>
        @if ((float) $booking->gst_amount > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('hall.book.gst') }}</span><span>{{ $money((float) $booking->gst_amount) }}</span></div> @endif
        @if ((float) $booking->booking_fee > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('hall.book.fee') }}</span><span>{{ $money((float) $booking->booking_fee) }}</span></div> @endif
        <div class="flex justify-between gap-3 border-t pt-2 font-semibold"><span>{{ $t('hall.book.total') }}</span><span>{{ $money((float) $booking->total) }}</span></div>
        @if ((float) $booking->deposit_amount > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('hall.book.deposit') }}</span><span>{{ $money((float) $booking->deposit_amount) }}</span></div> @endif
        <div class="flex justify-between gap-3"><span>{{ $t('hall.booking.paid') }}</span><span class="font-semibold">{{ $money((float) $booking->paid_amount) }}</span></div>
        @if (in_array($booking->status, ['awaiting_owner', 'confirmed'], true) && $booking->balanceLater() > 0 && $booking->balance_due_on)
            <p class="text-slate-600">{{ $t('hall.book.later', ['amount' => $money($booking->balanceLater()), 'date' => $booking->balance_due_on->format('j M Y')]) }}</p>
        @endif
        @if ((float) $booking->refunded_amount > 0 && $booking->status !== 'declined')
            <p class="text-slate-600">{{ $t('hall.booking.refunded', ['amount' => $money((float) $booking->refunded_amount)]) }}</p>
        @endif
    </div>

    @if (in_array($booking->status, ['awaiting_owner', 'confirmed'], true))
        <p class="mt-4 text-xs text-slate-500">{{ $refundLine }}</p>
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
                key: o.razorpay_key_id, order_id: o.razorpay_order_id, amount: o.amount, currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                prefill: @js(\App\Support\RazorpayPrefill::forCurrentUser()),
                description: @js($hall->name),
                handler: () => { window.location.href = d.done; },
                modal: { ondismiss: () => { window.location.href = d.back; } },
            }).open();
        });
    </script>
    @endscript
</div>
