{{-- Rider app 2026-11-04: open return pickups and shop / food delivery offers, each with its coloured label.
     Needs: $pickups, $shopOffers, $currencySymbol, $acceptAction, $declineAction, $acceptPickupAction. --}}
@php $km = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); @endphp
@foreach ($pickups as $p)
    <section class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm" wire:key="return-pickup-{{ $p->id }}" data-testid="offer-card">
        <div class="flex items-start justify-between gap-2">
            <x-rider.kind kind="return" />
            <span class="text-lg font-semibold tabular-nums text-emerald-700">{{ $currencySymbol }}{{ number_format((float) $p->pickup_fee, 2) }}</span>
        </div>
        <p class="mt-2 text-sm font-bold">Customer → {{ $p->store?->name }}</p>
        <p class="text-xs text-slate-600">{{ $p->order?->code }}@if ($p->distance_km !== null) · {{ $km($p->distance_km) }} km to the customer @endif</p>
        <p class="mt-1 text-xs text-slate-600">Collect nothing. Bring the item back to the shop.</p>
        <button type="button" wire:click="{{ $acceptPickupAction }}({{ $p->id }})" class="mt-3 min-h-11 w-full rounded-xl bg-blue-600 font-bold text-white">Accept pickup</button>
    </section>
@endforeach
@foreach ($shopOffers as $offer)
    @php $o = $offer->dispatchable; $food = $o->isFood(); @endphp
    <section class="rounded-2xl border border-slate-300 bg-white p-4 shadow-sm" wire:key="shop-offer-{{ $o->id }}" data-testid="offer-card">
        <div class="flex items-start justify-between gap-2">
            <x-rider.kind :kind="$food ? 'food' : 'shop'" />
            <span class="text-right text-sm font-semibold {{ $o->payment_method === 'cash' ? 'text-amber-800' : 'text-emerald-700' }}">{{ $o->payment_method === 'cash' ? 'Collect '.$currencySymbol.number_format((float) $o->total_amount, 2) : 'Prepaid' }}</span>
        </div>
        <p class="mt-2 text-sm font-bold">{{ $o->store?->name }} → {{ $o->deliveryAddress?->city ?: \Illuminate\Support\Str::limit((string) $o->deliveryAddress?->address_line, 40) }}</p>
        <p class="text-xs text-slate-600">{{ $o->code }}@if ($offer->distance_km !== null) · {{ $km($offer->distance_km) }} km to the {{ $food ? 'restaurant' : 'shop' }} @endif</p>
        @if ($food && $o->status === 'preparing' && $o->ready_by)
            <p class="mt-1 text-xs font-semibold text-orange-800">Cooking — ready about {{ $o->readyByLocal() }}</p>
        @endif
        <div class="mt-3 grid grid-cols-2 gap-2">
            <button type="button" wire:click="{{ $declineAction }}({{ $o->id }})" class="min-h-11 rounded-xl border border-slate-300 bg-white text-sm font-semibold text-slate-700">Decline</button>
            <button type="button" wire:click="{{ $acceptAction }}({{ $o->id }})" class="min-h-11 rounded-xl bg-blue-600 font-bold text-white">Accept</button>
        </div>
    </section>
@endforeach
