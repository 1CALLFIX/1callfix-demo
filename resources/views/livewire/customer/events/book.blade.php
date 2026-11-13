<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $field = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600';
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:px-6">
    <a href="{{ $backUrl }}" wire:navigate class="text-sm text-slate-600 hover:underline">&larr; {{ $event->title }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $t('event.buy.choose') }}</h1>
    <p class="mt-1 text-sm text-slate-600">{{ $event->title }} · {{ $when }} · {{ $event->venue_name }}</p>

    <div class="mt-5 space-y-2 rounded-2xl border border-slate-200 bg-white p-4 text-sm" data-testid="event-bill">
        @foreach ($quote['lines'] as $line)
            <div class="flex justify-between gap-3"><span>{{ $line['quantity'] }} × {{ $line['type']->name }}</span><span>{{ $money($line['line_total']) }}</span></div>
        @endforeach
        @if ($quote['gst'] > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('event.buy.gst') }}</span><span>{{ $money($quote['gst']) }}</span></div> @endif
        @if ($quote['fee'] > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('event.buy.fee') }}</span><span>{{ $money($quote['fee']) }}</span></div> @endif
        <div class="flex justify-between gap-3 border-t pt-2 text-base font-bold"><span>{{ $t('event.buy.total') }}</span><span>{{ $money($quote['total']) }}</span></div>
    </div>

    <div class="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
        <label class="block text-sm font-medium text-slate-700">{{ $t('event.buy.name') }}
            <input type="text" wire:model="buyerName" class="{{ $field }}">
        </label>
        @error('buyerName') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        <label class="block text-sm font-medium text-slate-700">{{ $t('event.buy.phone') }}
            <input type="tel" wire:model="buyerPhone" class="{{ $field }}">
        </label>
        @error('buyerPhone') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
    </div>

    @if ($error) <p role="alert" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="event-error">{{ $error }}</p> @endif

    @if ($canBuy)
        <div class="mt-5 space-y-3">
            <button type="button" wire:click="pay('online')" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="event-pay-online">{{ $t('event.buy.pay', ['amount' => $money($quote['total'])]) }}</button>
            @if ($walletOn)
                <button type="button" wire:click="pay('wallet')" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800 hover:border-blue-400">{{ $t('event.buy.wallet', ['balance' => $balance]) }}</button>
            @endif
            <p class="text-xs text-slate-500">{{ $t('event.buy.no_refund') }}</p>
        </div>
    @else
        <p class="mt-5 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('event.buy.opening_soon') }}</p>
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
                description: @js($event->title),
                // Paid: the tickets page waits for the bank's confirmation. Closed without paying: the tickets page,
                // where Pay stays open until the hold ends.
                handler: () => { window.location.href = d.done; },
                modal: { ondismiss: () => { window.location.href = d.back; } },
            }).open();
        });
    </script>
    @endscript
</div>
