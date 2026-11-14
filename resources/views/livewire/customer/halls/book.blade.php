<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $field = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600';
?>
<div class="mx-auto max-w-xl px-4 py-6 sm:px-6">
    <a href="{{ $backUrl }}" wire:navigate class="text-sm text-slate-600 hover:underline">&larr; {{ $hall->name }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $t('hall.book.title') }}</h1>
    <p class="mt-1 text-sm text-slate-600">{{ $hall->name }} · {{ $hall->hall_type }}</p>

    <div class="mt-5 space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.date') }}
            <input type="date" wire:model.live="date" min="{{ $first }}" max="{{ $last }}" class="{{ $field }}">
        </label>
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.slot') }}
            <select wire:model.live="slotId" class="{{ $field }}">
                @foreach ($hallSlots as $s) <option value="{{ $s->id }}">{{ $s->name }} · {{ $s->timeLabel() }}</option> @endforeach
            </select>
        </label>
        @if ($maxDays > 1)
            <div data-testid="hall-days">
                <p class="text-sm font-medium text-slate-700">{{ $t('hall.book.days') }}</p>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    @for ($n = 1; $n <= $maxDays; $n++)
                        <button type="button" wire:click="$set('days', '{{ $n }}')" @class(['min-h-11 rounded-full border px-4 text-sm font-semibold',
                            'border-blue-600 bg-blue-600 text-white' => (int) $days === $n, 'border-slate-300 bg-white text-slate-700' => (int) $days !== $n])>
                            {{ $n === 1 ? $t('hall.book.one_day') : $t('hall.book.n_days', ['n' => $n]) }}</button>
                    @endfor
                </div>
                @if ((int) $days > 1 && $lastDay)
                    <p class="mt-1.5 text-xs text-slate-600">{{ $t('hall.book.span', ['first' => \Illuminate\Support\Carbon::parse($date)->format('D, j M'), 'last' => $lastDay]) }}</p>
                @endif
            </div>
        @endif
        @if (! $dateOk)
            <p class="text-sm text-red-700">{{ $t('hall.book.date_range', ['first' => \Illuminate\Support\Carbon::parse($first)->format('j M Y'), 'last' => \Illuminate\Support\Carbon::parse($last)->format('j M Y')]) }}</p>
        @elseif ($chosenSlot && $free)
            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800" data-testid="hall-free">{{ $t('hall.book.free') }}</p>
        @elseif ($chosenSlot)
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700" data-testid="hall-taken">{{ $t('hall.book.taken') }}</p>
        @endif
    </div>

    @if ($quote)
        <div class="mt-4 space-y-2 rounded-2xl border border-slate-200 bg-white p-4 text-sm" data-testid="hall-bill">
            <div class="flex justify-between gap-3"><span>{{ $t('hall.book.price', ['slot' => $chosenSlot->name]) }}@if ($quote['days'] > 1) <span class="text-slate-500">({{ $money($quote['per_day']) }} × {{ $quote['days'] }})</span>@endif</span><span>{{ $money($quote['price']) }}</span></div>
            @if ($quote['gst'] > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('hall.book.gst') }}</span><span>{{ $money($quote['gst']) }}</span></div> @endif
            @if ($quote['fee'] > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('hall.book.fee') }}</span><span>{{ $money($quote['fee']) }}</span></div> @endif
            <div class="flex justify-between gap-3 border-t pt-2 font-semibold"><span>{{ $t('hall.book.total') }}</span><span>{{ $money($quote['total']) }}</span></div>
            @if ($quote['deposit'] > 0) <div class="flex justify-between gap-3 text-slate-600"><span>{{ $t('hall.book.deposit') }}</span><span>{{ $money($quote['deposit']) }}</span></div> @endif
            <div class="flex justify-between gap-3 rounded-lg bg-blue-50 px-3 py-2 text-base font-bold text-blue-900"><span>{{ $quote['full_now'] ? $t('hall.book.pay_full') : $t('hall.book.pay_advance') }}</span><span>{{ $money($quote['pay_now']) }}</span></div>
            @if ($quote['later'] > 0 && $dueOn)
                <p class="text-slate-600" data-testid="hall-later">{{ $t('hall.book.later', ['amount' => $money($quote['later']), 'date' => $dueOn]) }}</p>
            @endif
        </div>
    @endif

    <div class="mt-4 space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.occasion') }}
            <input type="text" wire:model="occasion" list="hall-occasions" maxlength="80" class="{{ $field }}">
            <datalist id="hall-occasions">
                @foreach (preg_split('/\s*,\s*/', $t('hall.book.occasions')) as $o) <option value="{{ $o }}"></option> @endforeach
            </datalist>
        </label>
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.guests') }}
            <input type="number" min="1" inputmode="numeric" wire:model="guests" class="{{ $field }}">
        </label>
        @error('guests') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.name') }}
            <input type="text" wire:model="name" class="{{ $field }}">
        </label>
        @error('name') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.phone') }}
            <input type="tel" wire:model="phone" class="{{ $field }}">
        </label>
        @error('phone') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        <label class="block text-sm font-medium text-slate-700">{{ $t('hall.book.notes') }}
            <textarea wire:model="notes" rows="2" maxlength="1000" class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-base shadow-sm"></textarea>
        </label>
    </div>

    @if ($error) <p role="alert" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="hall-error">{{ $error }}</p> @endif

    @if ($canBook)
        <div class="mt-5 space-y-3">
            <button type="button" wire:click="pay('online')" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="hall-pay-online">{{ $t('hall.book.pay', ['amount' => $money($quote['pay_now'])]) }}</button>
            @if ($walletOn)
                <button type="button" wire:click="pay('wallet')" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800 hover:border-blue-400">{{ $t('hall.book.wallet', ['balance' => $balance]) }}</button>
            @endif
            <p class="text-xs text-slate-500">{{ $t('hall.book.owner_accepts') }} {{ $refundLine }}</p>
        </div>
    @elseif ($quote && ! $quote['taxes_known'])
        <p class="mt-5 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('hall.book.opening_soon') }}</p>
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
                description: @js($hall->name),
                // Paid: the booking page waits for the bank's confirmation. Closed without paying: the booking page,
                // where Pay stays open until the hold ends.
                handler: () => { window.location.href = d.done; },
                modal: { ondismiss: () => { window.location.href = d.back; } },
            }).open();
        });
    </script>
    @endscript
</div>
