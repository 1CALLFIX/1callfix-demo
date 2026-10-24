@php
    $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2);
    $steps = [
        'placed' => 'Booked',
        'searching' => 'Finding a rider',
        'assigned' => 'Rider assigned',
        'picked_up' => 'Picked up',
        'delivered' => 'Delivered',
    ];
    $reached = match ($order->status) {
        'pending' => $order->isAwaitingOnlinePayment() ? 0 : 1,
        'searching_worker' => 1,
        'assigned', 'worker_en_route_pickup' => 2,
        'picked_up', 'en_route_dropoff' => 3,
        'delivered' => 4,
        default => -1,
    };
    $headline = match (true) {
        $order->status === 'cancelled' => 'Cancelled',
        $order->isAwaitingOnlinePayment() => 'Waiting for your payment',
        $order->status === 'disputed' => 'Coming back to you',
        $order->status === 'pending', $order->status === 'searching_worker' => 'Finding a rider near the pickup',
        $order->status === 'assigned' => 'Rider assigned',
        $order->status === 'worker_en_route_pickup' => 'Rider on the way to pickup',
        in_array($order->status, ['picked_up', 'en_route_dropoff'], true) => 'On the way to the recipient',
        $order->status === 'delivered' => 'Delivered',
        default => ucfirst(str_replace('_', ' ', $order->status)),
    };
    $rider = $order->assignedWorker?->user;
    $tz = app(\App\Services\TimezoneResolver::class);
@endphp

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8" @if ($live) wire:poll.10s @endif>
    <a href="{{ route('customer.parcels.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
        <x-icon name="arrow-left" class="h-4 w-4" /> My parcels
    </a>

    <div class="mt-3 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $headline }}</h1>
            <p class="text-sm text-slate-500">Parcel {{ $order->code }} · {{ $tz->format($order->created_at, $order->franchise, 'j M Y, g:i A') }}</p>
        </div>
        <span class="shrink-0 text-lg font-semibold tabular-nums">{{ $fmt($order->price_final ?? $order->price_quoted) }}</span>
    </div>

    @if ($notice)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $error }}</div>
    @endif

    {{-- Unpaid online order: nothing is sent to riders until this is paid. --}}
    @if ($order->isAwaitingOnlinePayment())
        <div class="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-4">
            <p class="text-sm text-blue-900">Pay {{ $fmt($order->price_quoted) }} to send this parcel to a rider. Unpaid orders are cancelled automatically after a short while.</p>
            <button type="button" wire:click="pay" wire:loading.attr="disabled"
                    class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700">Pay now</button>
        </div>
    @endif

    {{-- Progress --}}
    @if ($order->status !== 'cancelled')
        <ol class="mt-5 grid grid-cols-5 gap-1" aria-label="Delivery progress">
            @foreach (array_values($steps) as $i => $label)
                <li class="text-center">
                    <span @class(['mx-auto block h-1.5 rounded-full', 'bg-blue-600' => $i <= $reached, 'bg-slate-200' => $i > $reached])></span>
                    <span @class(['mt-1 block text-[11px] leading-tight', 'font-semibold text-slate-900' => $i === $reached, 'text-slate-500' => $i !== $reached])>{{ $label }}</span>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($order->status === 'disputed')
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            The recipient refused to pay at the door, so the rider is bringing the parcel back to you. Nothing has been charged. Give the rider your pickup code when they arrive.
        </div>
    @endif

    {{-- Codes the sender hands out --}}
    @if (in_array($order->status, \App\Models\ParcelOrder::ACTIVE_RIDER_STATUSES, true))
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @if (in_array($order->status, ['assigned', 'worker_en_route_pickup', 'disputed'], true))
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pickup code</p>
                    <p class="mt-1 text-3xl font-bold tracking-[0.3em] tabular-nums">{{ $order->pickup_otp }}</p>
                    <p class="mt-1 text-xs text-slate-600">Give this to the rider when they collect the parcel.</p>
                </div>
            @endif
            @if ($order->status !== 'disputed')
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Delivery code</p>
                <p class="mt-1 text-3xl font-bold tracking-[0.3em] tabular-nums">{{ $order->delivery_otp }}</p>
                <p class="mt-1 text-xs text-slate-600">Share it with {{ $order->recipient_name ?: 'the recipient' }}. They give it to the rider on delivery.</p>
            </div>
            @endif
        </div>
    @endif

    {{-- Rider --}}
    @if ($rider && $order->status !== 'cancelled')
        <div class="mt-5 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your rider</p>
                <p class="font-semibold">{{ $rider->name }}</p>
            </div>
            @if ($rider->phone && $order->status !== 'delivered')
                <a href="tel:{{ $rider->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium hover:bg-slate-50">Call</a>
            @endif
        </div>
    @endif

    {{-- Route --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        <div class="flex gap-3">
            <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-blue-600" aria-hidden="true"></span>
            <div>
                <p class="font-semibold">Pickup</p>
                <p class="text-slate-600">{{ $order->pickupAddress?->address_line }}</p>
                @if ($order->pickup_contact_name)<p class="text-slate-500">{{ $order->pickup_contact_name }} · {{ $order->pickup_contact_phone }}</p>@endif
            </div>
        </div>
        <div class="mt-3 flex gap-3">
            <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-600" aria-hidden="true"></span>
            <div>
                <p class="font-semibold">Drop</p>
                <p class="text-slate-600">{{ $order->dropoffAddress?->address_line }}</p>
                @if ($order->recipient_name)<p class="text-slate-500">{{ $order->recipient_name }} · {{ $order->recipient_phone }}</p>@endif
            </div>
        </div>
        <p class="mt-3 border-t border-slate-100 pt-3 text-slate-600">
            {{ \App\Services\Parcel\ParcelSettings::SIZES[$order->package_size] ?? ucfirst($order->package_size) }} package
            @if ($order->package_weight_kg) · {{ rtrim(rtrim(number_format((float) $order->package_weight_kg, 2), '0'), '.') }} kg @endif
            @if ($order->distance_km) · {{ rtrim(rtrim(number_format((float) $order->distance_km, 1), '0'), '.') }} km @endif
            @if ($order->package_description) · {{ $order->package_description }} @endif
        </p>
    </div>

    {{-- Price --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        <dl class="space-y-1.5">
            @foreach ($order->price_breakdown ?? [] as $line)
                <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ $line['label'] }}</dt><dd class="tabular-nums">{{ $fmt($line['amount']) }}</dd></div>
            @endforeach
            <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ $fmt($order->price_final ?? $order->price_quoted) }}</dd></div>
        </dl>
        <p class="mt-2 text-slate-600">
            @php $atDrop = $order->collectsCashAtDelivery(); @endphp
            Payment: {{ ['online' => 'Online', 'wallet' => 'Wallet', 'cash' => $atDrop ? 'Cash at drop' : 'Cash at pickup'][$order->payment_method] ?? $order->payment_method }}
            · {{ ['pending' => $order->payment_method === 'cash' ? ($atDrop ? 'The recipient pays the rider at drop' : 'Pay the rider at pickup') : 'Not paid', 'paid' => 'Paid', 'refunded' => 'Refunded', 'partially_refunded' => 'Partly refunded'][$order->payment_status] ?? $order->payment_status }}
        </p>
        @if ($order->status === 'cancelled' && (float) $order->cancellation_fee > 0)
            <p class="mt-1 text-slate-600">Cancellation charge: {{ $fmt($order->cancellation_fee) }}</p>
        @endif
    </div>

    {{-- Cancel --}}
    @if ($order->canBeCancelledByCustomer())
        <div class="mt-5">
            @if (! $confirmingCancel)
                <button type="button" wire:click="$set('confirmingCancel', true)" class="text-sm font-medium text-rose-700 underline underline-offset-2">Cancel this parcel</button>
            @else
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                    <p class="text-sm text-rose-900">
                        @if ($cancelFee > 0)
                            A rider is already assigned, so a cancellation charge of {{ $fmt($cancelFee) }} applies{{ $order->payment_status === 'paid' ? ' and is kept from your refund' : '' }}.
                        @else
                            No charge to cancel now.{{ $order->payment_status === 'paid' ? ' You get a full refund.' : '' }}
                        @endif
                    </p>
                    <label for="cancel-reason" class="mt-3 block text-sm font-medium text-rose-900">Reason</label>
                    <input id="cancel-reason" type="text" wire:model="cancelReason" maxlength="255" class="mt-1 block min-h-10 w-full rounded-lg border border-rose-300 px-3 text-sm">
                    @error('cancelReason') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="cancel" class="inline-flex min-h-10 items-center rounded-lg bg-rose-600 px-4 text-sm font-semibold text-white hover:bg-rose-700">Yes, cancel</button>
                        <button type="button" wire:click="$set('confirmingCancel', false)" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium">Keep it</button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Timeline --}}
    @if ($order->statusHistory->isNotEmpty())
        <details class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <summary class="cursor-pointer font-medium">History</summary>
            <ol class="mt-3 space-y-2">
                @foreach ($order->statusHistory->sortBy('changed_at') as $row)
                    <li class="flex justify-between gap-3">
                        <span class="text-slate-700">{{ ucfirst(str_replace('_', ' ', $row->status)) }}</span>
                        <span class="shrink-0 text-slate-500">{{ $row->changed_at ? $tz->format(\Illuminate\Support\Carbon::parse($row->changed_at), $order->franchise, 'j M, g:i A') : '' }}</span>
                    </li>
                @endforeach
            </ol>
        </details>
    @endif

    @if ($order->isAwaitingOnlinePayment())
        @assets
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        @endassets
    @endif
    @script
    <script>
        $wire.on('razorpay-open', (e) => {
            const o = e.order ?? e[0]?.order;
            if (! o || ! window.Razorpay) return;
            new window.Razorpay({
                key: o.razorpay_key_id,
                order_id: o.razorpay_order_id,
                amount: o.amount,
                currency: o.currency,
                name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                description: 'Parcel delivery',
                handler: () => window.location.reload(),
            }).open();
        });
    </script>
    @endscript
</div>
