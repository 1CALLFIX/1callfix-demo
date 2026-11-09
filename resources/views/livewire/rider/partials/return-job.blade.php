{{-- Rider app 2026-11-04: a shop return being brought back (customer → shop). Used on Home and the old Shop page.
     Needs: $returnJob, $currencySymbol, $otpModel, $collectAction, $dropAction. --}}
@php $picked = $returnJob->pickup_status === 'picked'; $addr = $returnJob->order?->deliveryAddress; @endphp
<section class="rounded-2xl border-2 border-slate-400 bg-white p-4 shadow-sm" data-testid="current-job">
    <div class="flex items-center justify-between gap-2">
        <x-rider.kind kind="return" size="md" />
        <span class="text-xs font-semibold text-slate-500">{{ $returnJob->order?->code }}</span>
    </div>
    <p class="mt-1 text-sm text-slate-600">You earn {{ $currencySymbol }}{{ number_format((float) $returnJob->pickup_fee, 2) }}. Collect nothing from the customer.</p>

    <div @class(['mt-3', 'opacity-50' => $picked])>
        <p class="text-xs font-semibold uppercase text-slate-500">1. Collect from the customer</p>
        <p class="font-semibold">{{ $returnJob->customer?->name }}</p>
        <p class="text-sm text-slate-600">{{ $addr?->address_line }}@if ($addr?->landmark), {{ $addr->landmark }}@endif</p>
        <div class="mt-2 flex flex-wrap gap-2 text-sm">
            @if ($addr)<x-ui.map-link :lat="$addr->lat" :lng="$addr->lng" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</x-ui.map-link>@endif
            @if ($returnJob->customer?->phone)<a href="tel:{{ $returnJob->customer->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call the customer</a>@endif
        </div>
        <ul class="mt-2 list-disc pl-5 text-sm text-slate-700">
            @foreach ($returnJob->items ?? [] as $line)
                @php $item = $returnJob->order?->items->firstWhere('id', $line['order_item_id']); @endphp
                <li>{{ $line['quantity'] }} × {{ $item?->product_name_snapshot ?? 'Item' }}@if ($item?->variant_name_snapshot) ({{ $item->variant_name_snapshot }})@endif</li>
            @endforeach
        </ul>
    </div>

    <div @class(['mt-4 border-t border-slate-100 pt-3', 'opacity-50' => ! $picked])>
        <p class="text-xs font-semibold uppercase text-slate-500">2. Take it back to the shop</p>
        <p class="font-semibold">{{ $returnJob->store?->name }}</p>
        <p class="text-sm text-slate-600">{{ $returnJob->store?->address_line }}</p>
        <div class="mt-2 flex flex-wrap gap-2 text-sm">
            <x-ui.map-link :lat="$returnJob->store?->lat" :lng="$returnJob->store?->lng" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Directions</x-ui.map-link>
            @if ($returnJob->store?->phone)<a href="tel:{{ $returnJob->store->phone }}" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 font-medium">Call the shop</a>@endif
        </div>
    </div>

    <form wire:submit="{{ $picked ? $dropAction : $collectAction }}" class="mt-4">
        <label for="return-otp" class="block text-sm font-medium">{{ $picked ? 'The shop’s drop code' : 'The customer’s pickup code' }}</label>
        <div class="mt-1 flex gap-2">
            <input id="return-otp" type="text" inputmode="numeric" maxlength="8" wire:model="{{ $otpModel }}" class="min-h-12 w-40 rounded-xl border border-slate-300 px-3 text-lg tracking-widest">
            <button type="submit" class="min-h-12 flex-1 rounded-xl bg-blue-600 font-bold text-white">{{ $picked ? 'Handed to the shop' : 'Collected' }}</button>
        </div>
    </form>
</section>
