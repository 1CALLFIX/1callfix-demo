@props(['product', 'city', 'qty' => 0, 'currencySymbol' => '₹', 'showStore' => true])
@php
    $image = $product->image_urls[0] ?? null;
    $url = route('customer.shop.product', [$city->slug, $product->store->slug, $product->slug]);
    $outOfStock = (int) $product->stock <= 0;
    $closed = ! $product->store->is_open;
@endphp
{{-- E-commerce Phase 2 product card. The shop name sits on every card: on 1CallFix you always know which local shop
     you are buying from. --}}
<article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:border-blue-300 hover:shadow-md" wire:key="p-{{ $product->id }}">
    <a href="{{ $url }}" wire:navigate class="relative block aspect-square bg-slate-50">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover">
        @else
            <span class="flex h-full w-full items-center justify-center text-3xl font-bold text-slate-300">{{ mb_substr($product->name, 0, 1) }}</span>
        @endif
        @if ($product->discount_percent)
            <span class="absolute left-2 top-2 rounded-full bg-blue-600 px-2 py-0.5 text-[11px] font-bold text-white">{{ rtrim(rtrim(number_format((float) $product->discount_percent, 1), '0'), '.') }}% off · online</span>
        @endif
    </a>
    <div class="flex flex-1 flex-col p-3">
        @if ($showStore)
            <p class="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $product->store->name }}</p>
        @endif
        <a href="{{ $url }}" wire:navigate class="mt-0.5 line-clamp-2 text-sm font-semibold text-slate-900">{{ $product->name }}</a>
        <p class="mt-1 text-base font-bold text-slate-900">
            {{ $currencySymbol }}{{ number_format($product->effective_price, 2) }}
            @if ($product->discount_percent)<span class="ml-1 text-xs font-normal text-slate-400 line-through">{{ $currencySymbol }}{{ number_format((float) $product->price, 2) }}</span>@endif
        </p>
        <div class="mt-auto pt-2">
            @if ($outOfStock)
                <p class="rounded-lg bg-slate-100 py-2 text-center text-xs font-semibold text-slate-500">Out of stock</p>
            @elseif ($closed)
                <p class="rounded-lg bg-slate-100 py-2 text-center text-xs font-semibold text-slate-500">Shop closed now</p>
            @elseif ($qty > 0)
                <div class="flex items-center justify-between rounded-lg bg-blue-600 text-white">
                    <button type="button" wire:click="removeOne({{ $product->id }})" class="min-h-10 px-3 text-lg font-bold" aria-label="One less {{ $product->name }}">−</button>
                    <span class="text-sm font-bold tabular-nums" aria-live="polite">{{ $qty }}</span>
                    <button type="button" wire:click="addToCart({{ $product->id }})" class="min-h-10 px-3 text-lg font-bold" aria-label="One more {{ $product->name }}">+</button>
                </div>
            @else
                <button type="button" wire:click="addToCart({{ $product->id }})" class="min-h-10 w-full rounded-lg border-2 border-blue-600 text-sm font-bold text-blue-700 hover:bg-blue-50">Add</button>
            @endif
        </div>
    </div>
</article>
