{{-- Rider app 2026-11-04: the shop / food delivery being carried. Used on Home and the old Shop deliveries page.
     Needs: $job (MarketplaceOrder), $currencySymbol, $otpModel (Livewire property), $deliverAction (method).
     Owner 2026-11-09: two stages, short words. Before "Reached restaurant" the rider sees only the restaurant and how far
     the drop is; after it, the pickup code, the items, the customer and (after hand-over) the code box. --}}
@php
    $cash = $job->payment_method === 'cash';
    $food = $job->isFood();
    $place = $food ? 'restaurant' : 'shop';
    $atStore = $job->rider_at_store_at !== null;
    $dropKm = $job->dropDistanceKm();
@endphp
<section @class(['rounded-2xl border-2 bg-white p-4 shadow-sm', 'border-orange-400' => $food, 'border-violet-400' => ! $food]) data-testid="current-job">
    <div class="flex items-center justify-between gap-2">
        <x-rider.kind :kind="$food ? 'food' : 'shop'" size="md" />
        <span class="text-xs font-semibold text-slate-500">{{ $job->code }}</span>
    </div>

    <div class="mt-3">
        <p class="text-xs font-semibold uppercase text-slate-500">1. Pickup</p>
        <p class="font-semibold">{{ $job->store->name }}</p>
        <p class="text-sm text-slate-600">{{ $job->store->address_line }}</p>
        <div class="mt-2 flex flex-wrap gap-2 text-sm">
            <x-ui.map-link :lat="$job->store->lat" :lng="$job->store->lng" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</x-ui.map-link>
            @if ($job->store->phone)<a href="tel:{{ $job->store->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call {{ $place }}</a>@endif
        </div>
        @if ($job->status === 'preparing')
            <p class="mt-2 rounded-lg bg-amber-50 p-2 text-sm font-semibold text-amber-900">Cooking{{ $job->ready_by ? ' · ready ~'.$job->readyByLocal() : '' }}</p>
        @endif
    </div>

    @unless ($atStore)
        @if ($dropKm !== null)
            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700" data-testid="drop-distance">
                Drop: <span class="font-bold">{{ rtrim(rtrim(number_format($dropKm, 1), '0'), '.') }} km</span> away
            </p>
        @endif
        {{-- Owner 2026-11-09: the phone's location at the tap is checked against the Reached distance (default 150 m). --}}
        <button type="button" x-data
                x-on:click="
                    $el.disabled = true;
                    navigator.geolocation
                        ? navigator.geolocation.getCurrentPosition(
                            p => { $wire.reachedStore(p.coords.latitude, p.coords.longitude); $el.disabled = false; },
                            () => { $wire.reachedStore(null, null); $el.disabled = false; },
                            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 })
                        : ($wire.reachedStore(null, null), $el.disabled = false)
                "
                class="mt-4 min-h-12 w-full rounded-xl bg-blue-600 text-base font-bold text-white disabled:opacity-60" data-testid="reached-store">
            Reached {{ $place }}
        </button>
        <p class="mt-2 text-center text-xs text-slate-500">Order details show after you reach.</p>
    @else
        {{-- The pickup code the rider says at the counter; the restaurant types it and hands over. --}}
        @if ($job->picked_up_at)
            <p class="mt-3 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" data-testid="picked-up">Picked up ✓ Deliver now</p>
        @else
            <div class="mt-3 rounded-xl bg-blue-600 p-4 text-center text-white" data-testid="rider-pickup-code">
                <p class="text-sm font-medium">Pickup code</p>
                <p class="mt-1 text-4xl font-extrabold tracking-[0.3em]">{{ $job->ensurePickupCode() }}</p>
                <p class="mt-1 text-xs text-blue-100">Tell this code at the counter</p>
            </div>
        @endif
        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3" data-testid="items-to-collect">
            <ul class="space-y-1.5">
                @foreach ($job->items as $item)
                    <li class="text-sm">
                        <span class="font-semibold">{{ $item->quantity }} ×</span> {{ $item->product_name_snapshot }}@if ($item->variant_name_snapshot) <span class="text-slate-600">({{ $item->variant_name_snapshot }})</span>@endif
                        @if (! empty($item->add_ons_snapshot))<span class="block pl-5 text-xs text-slate-600">+ {{ collect($item->add_ons_snapshot)->pluck('name')->implode(', ') }}</span>@endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-slate-500">{{ $job->items->sum('quantity') }} {{ $job->items->sum('quantity') === 1 ? 'item' : 'items' }} · check before leaving</p>
        </div>

        <div class="mt-4 border-t border-slate-100 pt-3">
            <p class="text-xs font-semibold uppercase text-slate-500">2. Deliver</p>
            {{-- Shop checkout polish 2026-11-02: an order sent to someone else names the receiver; the rider calls them. --}}
            @if ($job->recipient_name)
                <p class="font-semibold">{{ $job->recipient_name }} <span class="text-xs font-normal text-slate-500">(ordered by {{ $job->customer?->name }})</span></p>
            @else
                <p class="font-semibold">{{ $job->customer?->name }}</p>
            @endif
            <p class="text-sm text-slate-600">{{ $job->deliveryAddress?->address_line }}@if ($job->deliveryAddress?->landmark), {{ $job->deliveryAddress->landmark }}@endif</p>
            <div class="mt-2 flex flex-wrap gap-2 text-sm">
                @if ($job->deliveryAddress)<x-ui.map-link :lat="$job->deliveryAddress->lat" :lng="$job->deliveryAddress->lng" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</x-ui.map-link>@endif
                @if ($job->recipient_phone)<a href="tel:{{ $job->recipient_phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call {{ $job->recipient_name }}</a>@endif
                @if ($job->customer?->phone)<a href="tel:{{ $job->customer->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call customer</a>@endif
            </div>
            @if ($job->special_instructions)<p class="mt-1 text-sm text-slate-700">Note: {{ $job->special_instructions }}</p>@endif
        </div>

        <div @class(['mt-4 rounded-xl p-3 text-sm', 'bg-amber-50 text-amber-900' => $cash, 'bg-emerald-50 text-emerald-800' => ! $cash])>
            @if ($cash)
                <p class="font-bold">Collect {{ $currencySymbol }}{{ number_format((float) $job->total_amount, 2) }} cash</p>
            @else
                <p class="font-bold">Prepaid · collect nothing</p>
            @endif
        </div>

        @if ($job->status === 'ready' && ! $job->picked_up_at)
            <p class="mt-4 text-center text-sm text-slate-600">Waiting for handover…</p>
        @elseif ($job->status === 'ready')
            <form wire:submit="{{ $deliverAction }}" class="mt-4">
                <label for="shop-otp" class="block text-sm font-medium">3. Customer code</label>
                <div class="mt-1 flex gap-2">
                    <input id="shop-otp" type="text" inputmode="numeric" maxlength="8" wire:model="{{ $otpModel }}" class="min-h-12 w-40 rounded-xl border border-slate-300 px-3 text-lg tracking-widest">
                    <button type="submit" class="min-h-12 flex-1 rounded-xl bg-blue-600 font-bold text-white">Delivered</button>
                </div>
            </form>
        @endif
    @endunless
</section>
