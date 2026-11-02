@props(['product', 'city', 'qty' => 0, 'currencySymbol' => '₹', 'showStore' => true])
@php
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $image = $product->image_urls[0] ?? null;
    $url = route('customer.shop.product', [$city->slug, $product->store->slug, $product->slug]);
    $outOfStock = (int) $product->stock <= 0;
    $closed = ! $product->store->is_open;
    // Product choices 2026-11-02: a product with sizes/colours/portions is picked on its page ("Choose").
    $hasChoices = $product->hasChoices();
    $price = $hasChoices ? $product->fromPrice() : $product->effective_price;
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
            <span class="absolute left-2 top-2 rounded-full bg-blue-600 px-2 py-0.5 text-[11px] font-bold text-white">{{ $t('shop.card.off_online', ['percent' => rtrim(rtrim(number_format((float) $product->discount_percent, 1), '0'), '.')]) }}</span>
        @endif
    </a>
    <div class="flex flex-1 flex-col p-3">
        @if ($showStore)
            <p class="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $product->store->name }}</p>
        @endif
        <a href="{{ $url }}" wire:navigate class="mt-0.5 line-clamp-2 text-sm font-semibold text-slate-900">{{ $product->name }}</a>
        <p class="mt-1 text-base font-bold text-slate-900">
            @if ($hasChoices && $product->choicePricesDiffer())<span class="text-xs font-medium text-slate-500">{{ $t('shop.card.from') }}</span>@endif
            {{ $currencySymbol }}{{ number_format($price, 2) }}
            @if ($product->discount_percent && ! $hasChoices)<span class="ml-1 text-xs font-normal text-slate-400 line-through">{{ $currencySymbol }}{{ number_format((float) $product->price, 2) }}</span>@endif
        </p>
        @if ($product->unitLabel())<p class="text-xs text-slate-500">{{ $product->unitLabel() }}</p>@endif
        <div class="mt-auto pt-2">
            @if ($outOfStock)
                <p class="rounded-lg bg-slate-100 py-2 text-center text-xs font-semibold text-slate-500">{{ $t('shop.out_of_stock') }}</p>
            @elseif ($closed)
                <p class="rounded-lg bg-slate-100 py-2 text-center text-xs font-semibold text-slate-500">{{ $t('shop.card.shop_closed') }}</p>
            @elseif ($hasChoices)
                {{-- Choice pop-up 2026-11-02: opens the size/colour panel on this page; the product page when the page has no panel.
                     No wire:navigate here: it takes the click before Alpine can open the panel (seen live). --}}
                <a href="{{ $url }}" data-choose-product="{{ $product->id }}"
                   x-data x-on:click="if (document.querySelector('[data-choice-sheet]')) { $event.preventDefault(); $event.stopImmediatePropagation(); $dispatch('open-choice-sheet', { productId: {{ $product->id }} }) }"
                   class="flex min-h-10 w-full items-center justify-center rounded-lg border-2 border-blue-600 text-sm font-bold text-blue-700 hover:bg-blue-50">{{ $t('shop.card.choose') }}</a>
            @elseif ($qty > 0)
                <div class="flex items-center justify-between rounded-lg bg-blue-600 text-white">
                    <button type="button" wire:click="removeOne({{ $product->id }})" class="min-h-10 px-3 text-lg font-bold" aria-label="{{ $t('shop.card.one_less', ['item' => $product->name]) }}">−</button>
                    <span class="text-sm font-bold tabular-nums" aria-live="polite">{{ $qty }}</span>
                    <button type="button" wire:click="addToCart({{ $product->id }})" class="min-h-10 px-3 text-lg font-bold" aria-label="{{ $t('shop.card.one_more', ['item' => $product->name]) }}">+</button>
                </div>
            @else
                <button type="button" wire:click="addToCart({{ $product->id }})" class="min-h-10 w-full rounded-lg border-2 border-blue-600 text-sm font-bold text-blue-700 hover:bg-blue-50">{{ $t('shop.card.add') }}</button>
            @endif
        </div>
    </div>
</article>
