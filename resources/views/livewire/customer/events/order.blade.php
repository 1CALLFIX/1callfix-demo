<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:px-6" @if ($order->status === 'pending') wire:poll.5s @endif>
    <a href="{{ route('customer.events.tickets') }}" wire:navigate class="text-sm text-slate-600 hover:underline">&larr; {{ $t('event.tickets.title') }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $t('event.order.title') }}</h1>
    <p class="mt-1 text-sm text-slate-600">
        @if ($eventUrl) <a href="{{ $eventUrl }}" wire:navigate class="font-medium text-slate-900 underline">{{ $event->title }}</a> @else {{ $event->title }} @endif
        · {{ $when }} · {{ $event->venue_name }}
    </p>
    <p class="mt-1 font-mono text-xs text-slate-500">{{ $order->code }}</p>

    @if ($error) <p role="alert" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $error }}</p> @endif

    @if ($order->status === 'pending')
        <div role="status" class="mt-5 space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="event-order-pending">
            <p>{{ $justPaid ? $t('event.order.confirming') : $t('event.order.waiting', ['time' => $holdUntil]) }}</p>
            @unless ($justPaid)
                <button type="button" wire:click="payNow" class="flex min-h-11 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700">{{ $t('event.order.pay_now') }} · {{ $money((float) $order->total) }}</button>
            @endunless
        </div>
    @elseif ($order->status === 'cancelled')
        <div role="alert" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" data-testid="event-order-cancelled">{{ $t('event.order.cancelled', ['reason' => $order->cancellation_note]) }}</div>
    @else
        <p class="mt-5 text-sm text-slate-700">{{ $t('event.order.at_gate') }}</p>
        <ul class="mt-3 space-y-4" data-testid="event-tickets-list">
            @foreach ($order->tickets as $i => $ticket)
                <li class="rounded-2xl border border-slate-200 bg-white p-4 text-center shadow-sm" data-testid="event-ticket">
                    <p class="text-xs font-semibold uppercase text-slate-500">{{ $t('event.order.ticket_of', ['n' => $i + 1, 'total' => $order->tickets->count()]) }}</p>
                    <p class="mt-1 font-semibold">{{ $ticket->ticketType?->name }} @if ($ticket->admits > 1) · {{ $t('event.show.admits', ['n' => $ticket->admits]) }} @endif</p>
                    @if ($ticket->status === 'used')
                        <p class="mt-3 rounded-lg bg-slate-100 py-6 text-lg font-bold text-slate-500">{{ $t('event.order.used') }}</p>
                    @else
                        <div class="mx-auto mt-3 aspect-square w-56 max-w-full" data-qr="{{ $ticket->code }}" wire:ignore aria-label="QR code {{ $ticket->code }}"></div>
                    @endif
                    <p class="mt-2 font-mono text-sm tracking-widest">{{ $ticket->code }}</p>
                    <p class="text-xs text-slate-500">{{ $order->buyer_name }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-5 space-y-1 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        @foreach ($order->items as $item)
            <div class="flex justify-between gap-3"><span>{{ $item->quantity }} × {{ $item->name }}</span><span>{{ $money((float) $item->line_total) }}</span></div>
        @endforeach
        @if ((float) $order->tax_amount > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('event.buy.gst') }}</span><span>{{ $money((float) $order->tax_amount) }}</span></div> @endif
        @if ((float) $order->booking_fee > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('event.buy.fee') }}</span><span>{{ $money((float) $order->booking_fee) }}</span></div> @endif
        <div class="flex justify-between gap-3 border-t pt-2 font-bold"><span>{{ $t('event.buy.total') }}</span><span>{{ $money((float) $order->total) }}</span></div>
    </div>

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
                description: @js($event->title),
                handler: () => { window.location.href = d.done; },
                modal: { ondismiss: () => { window.location.href = d.back; } },
            }).open();
        });
    </script>
    @endscript
</div>
