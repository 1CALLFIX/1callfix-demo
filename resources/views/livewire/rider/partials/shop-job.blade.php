{{-- Rider app 2026-11-04: the shop / food delivery being carried. Used on Home and the old Shop deliveries page.
     Needs: $job (MarketplaceOrder), $currencySymbol, $otpModel (Livewire property), $deliverAction (method). --}}
@php
    $cash = $job->payment_method === 'cash';
    $food = $job->isFood();
    $place = $food ? 'restaurant' : 'shop';
@endphp
<section @class(['rounded-2xl border-2 bg-white p-4 shadow-sm', 'border-orange-400' => $food, 'border-violet-400' => ! $food]) data-testid="current-job">
    <div class="flex items-center justify-between gap-2">
        <x-rider.kind :kind="$food ? 'food' : 'shop'" size="md" />
        <span class="text-xs font-semibold text-slate-500">{{ $job->code }}</span>
    </div>

    <div class="mt-3">
        <p class="text-xs font-semibold uppercase text-slate-500">1. Collect from the {{ $place }}</p>
        <p class="font-semibold">{{ $job->store->name }}</p>
        <p class="text-sm text-slate-600">{{ $job->store->address_line }}</p>
        <div class="mt-2 flex flex-wrap gap-2 text-sm">
            <a href="https://www.google.com/maps/dir/?api=1&destination={{ $job->store->lat }},{{ $job->store->lng }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</a>
            @if ($job->store->phone)<a href="tel:{{ $job->store->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call the {{ $place }}</a>@endif
        </div>
        <p class="mt-1 text-xs text-slate-500">{{ $job->items->sum('quantity') }} items. Check the count before you leave.</p>
        @if ($job->status === 'preparing')
            <p class="mt-2 rounded-lg bg-amber-50 p-2 text-sm font-semibold text-amber-900">The food is cooking{{ $job->ready_by ? ', ready about '.$job->readyByLocal() : '' }}. Go to the restaurant; you can take it once they mark it ready.</p>
        @endif
    </div>

    <div class="mt-4 border-t border-slate-100 pt-3">
        <p class="text-xs font-semibold uppercase text-slate-500">2. Deliver to</p>
        {{-- Shop checkout polish 2026-11-02: an order sent to someone else names the receiver; the rider calls them. --}}
        @if ($job->recipient_name)
            <p class="font-semibold">{{ $job->recipient_name }} <span class="text-xs font-normal text-slate-500">(ordered by {{ $job->customer?->name }})</span></p>
        @else
            <p class="font-semibold">{{ $job->customer?->name }}</p>
        @endif
        <p class="text-sm text-slate-600">{{ $job->deliveryAddress?->address_line }}@if ($job->deliveryAddress?->landmark), {{ $job->deliveryAddress->landmark }}@endif</p>
        <div class="mt-2 flex flex-wrap gap-2 text-sm">
            @if ($job->deliveryAddress)<a href="https://www.google.com/maps/dir/?api=1&destination={{ $job->deliveryAddress->lat }},{{ $job->deliveryAddress->lng }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</a>@endif
            @if ($job->recipient_phone)<a href="tel:{{ $job->recipient_phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call {{ $job->recipient_name }}</a>@endif
            @if ($job->customer?->phone)<a href="tel:{{ $job->customer->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call the customer</a>@endif
        </div>
        @if ($job->special_instructions)<p class="mt-1 text-sm text-slate-700">Note: {{ $job->special_instructions }}</p>@endif
    </div>

    <div @class(['mt-4 rounded-xl p-3 text-sm', 'bg-amber-50 text-amber-900' => $cash, 'bg-emerald-50 text-emerald-800' => ! $cash])>
        @if ($cash)
            <p class="font-bold">Collect {{ $currencySymbol }}{{ number_format((float) $job->total_amount, 2) }} in cash before you hand it over.</p>
        @else
            <p class="font-bold">Prepaid. Collect nothing.</p>
        @endif
    </div>

    @if ($job->status === 'ready')
        <form wire:submit="{{ $deliverAction }}" class="mt-4">
            <label for="shop-otp" class="block text-sm font-medium">3. Customer's code</label>
            <div class="mt-1 flex gap-2">
                <input id="shop-otp" type="text" inputmode="numeric" maxlength="8" wire:model="{{ $otpModel }}" class="min-h-12 w-40 rounded-xl border border-slate-300 px-3 text-lg tracking-widest">
                <button type="submit" class="min-h-12 flex-1 rounded-xl bg-blue-600 font-bold text-white">Delivered</button>
            </div>
        </form>
    @endif
</section>
