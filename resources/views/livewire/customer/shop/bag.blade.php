@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
{{-- Shop Phase A 2026-11-02: the shop cart, redrawn after a walk-through of Amazon, Flipkart and Meesho — one card per
     shop with large photos, the choice under each name, offer prices with the price before, a free-delivery bar, a
     green "you save" line and one clear checkout button with the total. Every word from SiteText (English + Telugu). --}}
<div @class(['mb-bottom-nav' => ! $embedded])>
    <div @class(['mx-auto max-w-3xl px-4 py-6 sm:px-6' => ! $embedded, 'mt-10' => $embedded])>
        @if ($embedded)
            <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900" data-testid="cart-shop">
                <x-icon name="shopping-bag" class="h-5 w-5 text-blue-600" /> {{ $t('cart.shop_heading') }}
            </h2>
            <p class="mt-1 text-sm text-slate-600">{{ $t('cart.shop_intro') }}</p>
        @else
            <h1 class="text-2xl font-bold tracking-tight">{{ $t('cart.title') }}</h1>
        @endif

        @if ($message)
            <p role="status" class="mt-3 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $message }}</p>
        @endif

        @forelse ($groups as $g)
            @php
                $store = $g['store']; $citySlug = $store->franchise?->city?->slug;
                // Food F3: a restaurant's block links to its menu.
                $storeUrl = $citySlug ? ($g['food'] ? route('customer.food.restaurant', [$citySlug, $store->slug]) : route('customer.shop.store', [$citySlug, $store->slug])) : null;
            @endphp
            <section class="mt-5 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" wire:key="bag-{{ $store->id }}">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/70 px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <h3 class="truncate font-bold text-slate-900">
                            @if ($storeUrl)<a href="{{ $storeUrl }}" wire:navigate class="hover:underline">{{ $store->name }}</a>@else{{ $store->name }}@endif
                        </h3>
                        @if ($g['promise'])<p class="flex items-center gap-1 text-xs font-medium text-slate-600"><x-icon name="clock" class="h-3.5 w-3.5 text-blue-600" /> {{ $g['promise'] }}</p>@endif
                    </div>
                    @unless ($store->isOpenNow())<span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-600">{{ $t('cart.closed_now') }}</span>@endunless
                </header>

                <ul class="divide-y divide-slate-100 px-4 sm:px-5">
                    @foreach ($g['lines'] as $line)
                        @php
                            $p = $line->product;
                            $gone = ! $p || ! $p->is_active || ! $p->is_approved;
                            $soldOut = ! $gone && $g['food'] && $p->isSoldOutNow();
                            $img = $line->variant?->imageUrl() ?? ($p?->image_urls[0] ?? null);
                            $productUrl = $p && $citySlug ? ($g['food'] ? $storeUrl : route('customer.shop.product', [$citySlug, $store->slug, $p->slug])) : null;
                        @endphp
                        <li class="flex gap-3 py-4" wire:key="line-{{ $line->id }}">
                            <a @if ($productUrl) href="{{ $productUrl }}" wire:navigate @endif class="block h-20 w-20 shrink-0 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                                @if ($img)<img src="{{ $img }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                @else<span class="flex h-full w-full items-center justify-center text-2xl font-bold text-slate-300">{{ mb_substr($p?->name ?? '?', 0, 1) }}</span>@endif
                            </a>
                            <div class="min-w-0 flex-1">
                                <p class="line-clamp-2 text-sm font-semibold text-slate-900">{{ $p?->name ?? $t('cart.item_removed') }}</p>
                                @if ($line->variant)<p class="mt-0.5 text-xs text-slate-500">{{ $line->variant->name }}</p>@endif
                                @if ($line->addOnModels()->isNotEmpty())<p class="mt-0.5 text-xs text-slate-500" data-testid="cart-addons">+ {{ $line->addOnModels()->pluck('name')->implode(', ') }}</p>@endif
                                @if ($gone)
                                    <p class="mt-1 text-xs font-semibold text-red-700">{{ $t('cart.item_gone') }}</p>
                                @elseif ($soldOut)
                                    <p class="mt-1 text-xs font-semibold text-red-700">{{ $t('food.cart.sold_out_line') }}</p>
                                @else
                                    <p class="mt-1 flex flex-wrap items-baseline gap-x-1.5 text-sm">
                                        <span class="font-bold text-slate-900">{{ $currencySymbol }}{{ number_format($line->unitPriceWithAddOns(), 2) }}</span>
                                        @if ($line->isDiscounted())
                                            <span class="text-xs text-slate-400 line-through">{{ $currencySymbol }}{{ number_format($line->listUnitPrice(), 2) }}</span>
                                            <span class="text-xs font-bold text-emerald-600">{{ $t('shop.card.off_online', ['percent' => rtrim(rtrim(number_format((float) $p->discount_percent, 1), '0'), '.')]) }}</span>
                                        @endif
                                    </p>
                                @endif
                                <div class="mt-2 flex items-center gap-4">
                                    @unless ($gone || $soldOut)
                                        <div class="inline-flex items-center rounded-full border border-slate-300 bg-white">
                                            <button type="button" wire:click="change({{ $line->id }}, -1)" class="flex h-9 w-9 items-center justify-center text-lg font-bold text-slate-700" aria-label="{{ $t('cart.one_less') }}">−</button>
                                            <span class="min-w-6 text-center text-sm font-bold tabular-nums">{{ $line->quantity }}</span>
                                            <button type="button" wire:click="change({{ $line->id }}, 1)" class="flex h-9 w-9 items-center justify-center text-lg font-bold text-slate-700" aria-label="{{ $t('cart.one_more') }}">+</button>
                                        </div>
                                    @endunless
                                    <button type="button" wire:click="removeLine({{ $line->id }})" class="text-xs font-semibold text-slate-500 hover:text-red-700">{{ $t('cart.remove') }}</button>
                                </div>
                            </div>
                            @unless ($gone || $soldOut)
                                <p class="shrink-0 text-right text-sm font-bold tabular-nums text-slate-900">{{ $currencySymbol }}{{ number_format($line->unitPriceWithAddOns() * $line->quantity, 2) }}</p>
                            @endunless
                        </li>
                    @endforeach
                </ul>

                <div class="space-y-2 border-t border-slate-100 px-4 py-4 sm:px-5">
                    @if ($g['freeAbove'] !== null)
                        @php $toFree = max(0, $g['freeAbove'] - $g['subtotal']); @endphp
                        <div class="rounded-2xl bg-emerald-50 px-3 py-2.5 text-xs font-medium text-emerald-800" data-testid="free-delivery-progress">
                            {{ $toFree > 0 ? $t('cart.free_delivery_short', ['amount' => $currencySymbol.number_format($toFree, 2)]) : $t('cart.free_delivery_ok') }}
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-emerald-100" aria-hidden="true">
                                <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ (int) min(100, round($g['subtotal'] / $g['freeAbove'] * 100)) }}%"></div>
                            </div>
                        </div>
                    @endif
                    @if ($g['saving'] > 0)
                        <p class="flex items-center gap-1.5 rounded-2xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" data-testid="cart-saving">
                            <x-icon name="tag" class="h-4 w-4" /> {{ $t('cart.saving', ['amount' => $currencySymbol.number_format($g['saving'], 2)]) }}
                        </p>
                    @endif
                    @if ($g['short'] > 0)
                        <p class="text-xs font-medium text-amber-700">{{ $t('cart.min_short', ['amount' => $currencySymbol.number_format($g['short'], 2)]) }}</p>
                    @endif

                    <div class="flex items-center justify-between gap-3 pt-1">
                        <div>
                            <p class="text-xs text-slate-500">{{ $t('cart.items_total') }} ({{ $g['count'] }})</p>
                            <p class="text-xl font-extrabold tabular-nums text-slate-900">{{ $currencySymbol }}{{ number_format($g['subtotal'], 2) }}
                                @if ($g['saving'] > 0)<span class="ml-1 text-sm font-normal text-slate-400 line-through">{{ $currencySymbol }}{{ number_format($g['listTotal'], 2) }}</span>@endif
                            </p>
                        </div>
                        @if ($g['canCheckout'] && $g['short'] <= 0)
                            <button type="button" wire:click="checkout({{ $store->id }})" class="inline-flex min-h-12 items-center rounded-2xl bg-amber-400 px-6 text-sm font-bold text-slate-900 shadow-sm hover:bg-amber-300" data-testid="cart-checkout">
                                {{ $guest ? $t('cart.sign_in_checkout') : $t('cart.checkout_shop', ['shop' => $store->name]) }}
                            </button>
                        @else
                            <span class="inline-flex min-h-12 items-center rounded-2xl bg-slate-200 px-6 text-sm font-bold text-slate-500">{{ $t('cart.checkout_off') }}</span>
                        @endif
                    </div>
                    @if ($guest)<p class="text-right text-xs text-slate-500">{{ $t('cart.guest_note') }}</p>@endif
                </div>
            </section>
        @empty
            @unless ($embedded)
                <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                    <p class="font-semibold">{{ $t('cart.empty') }}</p>
                </div>
            @endunless
        @endforelse
    </div>
</div>
