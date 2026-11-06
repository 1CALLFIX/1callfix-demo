@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
{{-- Sticky buy bar 2026-11-04: on phones, once the main Add / Buy now buttons scroll away, the same buttons stay
     at the bottom of the screen (and the floating cart bar steps aside so they don't stack). --}}
<div class="mb-bottom-nav"
     x-data="{
        stuck: false,
        init() {
            const target = this.$refs.buy;
            if (! target || ! ('IntersectionObserver' in window)) return;
            const phone = window.matchMedia('(max-width: 639px)');
            new IntersectionObserver(([e]) => { this.stuck = phone.matches && ! e.isIntersecting && e.boundingClientRect.top < 0; }).observe(target);
        },
        call(method, id = null) {
            (id === null ? this.$wire.call(method) : this.$wire.call(method, id)).then(() => this.$nextTick(() => {
                const note = document.querySelector('[data-testid=choice-missing], [data-testid=cart-message]');
                if (note) note.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }));
        },
     }">
    {{-- Photo first on phones (2026-11-07, Flipkart / Amazon): edge to edge under the header; breadcrumbs from tablet up. --}}
    <div class="mx-auto max-w-6xl px-4 pb-28 sm:px-6 sm:py-6 lg:px-8">
        <x-customer.breadcrumbs class="hidden sm:block" :items="[
            ['label' => $t('shop.crumb_shop'), 'url' => route('customer.shop.home', $city->slug)],
            ['label' => $store->name, 'url' => route('customer.shop.store', [$city->slug, $store->slug])],
            ['label' => $product->name, 'url' => null],
        ]" />

        <div class="grid gap-6 sm:mt-4 md:grid-cols-2">
            @php
                // Product choices 2026-11-02: the picked choice's photo comes first.
                $images = $product->image_urls;
                if ($selected && $selected->imageUrl()) {
                    $images = array_values(array_unique(array_merge([$selected->imageUrl()], $images)));
                }
                $hasChoices = $choices !== [];
                $priceNow = $selected ? $selected->effectivePrice() : ($hasChoices ? $product->fromPrice() : $product->effective_price);
                $priceWas = $product->discount_percent ? ($selected ? $selected->listPrice() : ($hasChoices ? null : (float) $product->price)) : null;
                $stockNow = $selected ? (int) $selected->stock : (int) $product->stock;
            @endphp
            <div x-data="{ shown: 0 }">
                <div class="relative -mx-4 aspect-square overflow-hidden border-b border-slate-200 bg-white sm:mx-0 sm:rounded-3xl sm:border">
                    {{-- ♡ + Share on the photo's corner (owner 2026-11-07, Flipkart / Amazon pattern) --}}
                    <x-customer.item-actions :item="'product:'.$product->id" :title="$product->name" :image="$product->image_urls[0] ?? null" class="absolute right-3 top-3 z-10" />
                    @forelse ($images as $i => $src)
                        <img src="{{ $src }}" alt="{{ $product->name }}" x-show="shown === {{ $i }}" @if ($i > 0) style="display:none" @endif class="h-full w-full object-contain">
                    @empty
                        <span class="flex h-full w-full items-center justify-center text-6xl font-bold text-slate-200">{{ mb_substr($product->name, 0, 1) }}</span>
                    @endforelse
                </div>
                @if (count($images) > 1)
                    <div class="mt-2 flex gap-2 overflow-x-auto">
                        @foreach ($images as $i => $src)
                            <button type="button" x-on:click="shown = {{ $i }}" class="h-16 w-16 overflow-hidden rounded-xl border-2"
                                    x-bind:class="shown === {{ $i }} ? 'border-blue-600' : 'border-slate-200'" aria-label="{{ $t('shop.product.photo', ['number' => $i + 1]) }}">
                                <img src="{{ $src }}" alt="" class="h-full w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <a href="{{ route('customer.shop.store', [$city->slug, $store->slug]) }}" wire:navigate class="text-sm font-semibold uppercase tracking-wide text-[var(--mod-ink)]">{{ $store->name }}</a>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $product->name }}</h1>

                {{-- Shop Phase A 2026-11-02: price block like the big marketplaces: offer price, % off, the price before,
                     "Inclusive of all taxes", the unit, and the online-only offer note. --}}
                @php $pct = $product->discount_percent ? rtrim(rtrim(number_format((float) $product->discount_percent, 1), '0'), '.') : null; @endphp
                <div class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    @if (! $selected && $product->choicePricesDiffer())<span class="text-base font-medium text-slate-500">{{ $t('shop.card.from') }}</span>@endif
                    <span class="text-3xl font-extrabold tracking-tight text-slate-900">{{ $currencySymbol }}{{ number_format($priceNow, 2) }}</span>
                    @if ($priceWas !== null)
                        <span class="text-base text-slate-400 line-through">{{ $currencySymbol }}{{ number_format($priceWas, 2) }}</span>
                    @endif
                    @if ($pct)<span class="text-base font-bold text-emerald-600">{{ $t('shop.product.percent_off', ['percent' => $pct]) }}</span>@endif
                </div>
                <p class="mt-0.5 text-xs text-slate-500">{{ $t('shop.product.incl_taxes') }}@if ($product->unitLabel()) · <span data-testid="product-unit">{{ $t('shop.product.per_unit', ['unit' => $product->unitLabel()]) }}</span>@endif</p>
                @if ($pct)
                    <p class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-sm font-medium text-emerald-800"><x-icon name="tag" class="h-4 w-4" /> {{ $t('shop.product.off_note', ['percent' => $pct]) }}</p>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span @class(['font-semibold', 'text-emerald-700' => $stockNow > 5, 'text-amber-700' => $stockNow > 0 && $stockNow <= 5, 'text-red-700' => $stockNow <= 0])>
                        {{ $stockNow <= 0 ? $t('shop.out_of_stock') : ($stockNow <= 5 ? $t('shop.product.only_left', ['count' => $stockNow]) : $t('shop.product.in_stock')) }}
                    </span>
                    @if ($deliveryPromise)
                        <span class="inline-flex items-center gap-1 font-medium text-slate-700" data-testid="delivery-promise"><x-icon name="clock" class="h-4 w-4 text-blue-600" /> {{ $deliveryPromise }}</span>
                    @endif
                </div>

                @foreach ($choices as $name => $values)
                    @php $missing = $missingChoice === $name; @endphp
                    <div wire:key="choice-{{ md5($name) }}" @class(['mt-4 rounded-2xl border p-3 transition', 'border-red-300 bg-red-50' => $missing, 'border-slate-200 bg-white' => ! $missing])
                         @if ($missing) data-testid="choice-missing" @endif>
                        <p class="text-sm font-semibold text-slate-900">{{ $name }}@if (isset($picked[$name]))<span class="font-normal text-slate-600">: {{ $picked[$name] }}</span>@endif</p>
                        <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ $name }}">
                            @foreach ($values as $value => $inStock)
                                @php $on = ($picked[$name] ?? null) === (string) $value; $left = $lowStock[$name][$value] ?? null; @endphp
                                <div class="flex flex-col items-center">
                                    <button type="button" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" wire:click="pick({{ \Illuminate\Support\Js::from($name) }}, {{ \Illuminate\Support\Js::from((string) $value) }})"
                                            @class(['min-h-11 min-w-12 rounded-full border-2 px-4 text-sm font-semibold transition',
                                                'border-blue-600 bg-blue-600 text-white shadow-sm' => $on,
                                                'border-slate-300 bg-white text-slate-800 hover:border-blue-500' => ! $on && $inStock,
                                                'border-dashed border-slate-200 bg-slate-50 text-slate-400 line-through' => ! $on && ! $inStock])>{{ $value }}</button>
                                    @if ($left)<span class="mt-0.5 text-[11px] font-semibold text-amber-700">{{ $t('shop.product.left', ['count' => $left]) }}</span>@endif
                                </div>
                            @endforeach
                        </div>
                        @if ($missing)<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $t('shop.product.select_choice', ['choice' => mb_strtolower($name)]) }}</p>@endif
                    </div>
                @endforeach

                @if ($cartMessage)
                    <p role="status" data-testid="cart-message" class="mt-3 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
                @endif

                {{-- Add to Cart + Buy now (Amazon / Flipkart / Meesho). Once in the cart: the stepper and Buy now. --}}
                @php
                    $soldOut = $hasChoices ? ($selected && $selected->stock <= 0) : $product->stock <= 0;
                    $inCartQty = $hasChoices ? $choiceQty : $qty;
                    $addAction = $hasChoices ? 'addChoice' : 'addToCart('.$product->id.')';
                    $lessAction = $hasChoices ? 'removeChoiceOne' : 'removeOne('.$product->id.')';
                @endphp
                <div x-ref="buy" class="mt-5 grid max-w-md grid-cols-2 gap-3">
                    @if (! $store->is_open)
                        <p class="col-span-2 rounded-2xl bg-slate-100 py-3.5 text-center font-semibold text-slate-500">{{ $t('shop.product.shop_closed') }}</p>
                    @elseif ($soldOut)
                        <p class="col-span-2 rounded-2xl bg-slate-100 py-3.5 text-center font-semibold text-slate-500">{{ $hasChoices ? $t('shop.product.choice_sold_out', ['choice' => $selected->name]) : $t('shop.out_of_stock') }}</p>
                    @else
                        @if ($inCartQty > 0)
                            <div class="flex items-center justify-between rounded-2xl border-2 border-blue-600 text-blue-700">
                                <button type="button" wire:click="{{ $lessAction }}" class="min-h-12 px-4 text-xl font-bold" aria-label="{{ $t('shop.product.one_less') }}">−</button>
                                <span class="text-sm font-bold tabular-nums" aria-live="polite">{{ $t('shop.product.in_bag', ['count' => $inCartQty]) }}</span>
                                <button type="button" wire:click="{{ $addAction }}" class="min-h-12 px-4 text-xl font-bold" aria-label="{{ $t('shop.product.one_more') }}">+</button>
                            </div>
                        @else
                            <button type="button" wire:click="{{ $addAction }}" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl border-2 border-blue-600 bg-white font-bold text-blue-700 hover:bg-blue-50" data-testid="add-to-cart">
                                <x-icon name="shopping-cart" class="h-5 w-5" /> {{ $t('shop.product.add_to_bag') }}
                            </button>
                        @endif
                        <button type="button" wire:click="buyNow" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-amber-400 font-bold text-slate-900 shadow-sm hover:bg-amber-300" data-testid="buy-now">
                            <x-icon name="bolt" class="h-5 w-5" /> {{ $t('shop.product.buy_now') }}
                        </button>
                    @endif
                </div>

                @if ($product->description)
                    <div class="mt-6">
                        <h2 class="text-sm font-semibold text-slate-900">{{ $t('shop.product.about') }}</h2>
                        <p class="mt-1 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ $product->description }}</p>
                    </div>
                @endif

                <ul class="mt-6 grid gap-2 text-sm text-slate-700 sm:grid-cols-3">
                    <li class="flex items-start gap-2 rounded-2xl bg-slate-50 p-3"><x-icon name="building" class="mt-0.5 h-4 w-4 shrink-0 text-blue-600" /> {{ $t('shop.product.sold_by', ['shop' => $store->name, 'city' => $city->name]) }}</li>
                    <li class="flex items-start gap-2 rounded-2xl bg-slate-50 p-3"><x-icon name="shield" class="mt-0.5 h-4 w-4 shrink-0 text-blue-600" /> {{ $t('shop.product.delivered_by') }}</li>
                    <li class="flex items-start gap-2 rounded-2xl bg-slate-50 p-3"><x-icon name="arrow-path" class="mt-0.5 h-4 w-4 shrink-0 text-blue-600" /> {{ $t('shop.product.returns', ['days' => \App\Support\MarketplaceSettings::get('marketplace.return_window_days')]) }}</li>
                </ul>
            </div>
        </div>

        @if ($more->isNotEmpty())
            <section class="mt-10">
                <h2 class="text-lg font-bold">{{ $t('shop.product.more_from', ['shop' => $store->name]) }}</h2>
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($more as $p)
                        <x-shop.product-card :product="$p" :city="$city" :qty="$inCart[$p->id] ?? 0" :currency-symbol="$currencySymbol" :show-store="false" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    @if ($store->is_open && ! $soldOut)
        @php
            // Only fixed method names and the integer product id go in here, so the raw echo below is safe.
            $stickyAdd = $hasChoices ? "call('addChoice')" : "call('addToCart', ".(int) $product->id.')';
            $stickyLess = $hasChoices ? "call('removeChoiceOne')" : "call('removeOne', ".(int) $product->id.')';
        @endphp
        <div x-show="stuck" style="display:none" x-transition.opacity
             class="fixed inset-x-0 bottom-16 z-40 border-t border-slate-200 bg-white/95 px-4 py-2.5 shadow-[0_-4px_16px_rgba(15,23,42,0.08)] backdrop-blur sm:hidden"
             data-testid="sticky-buy-bar">
            <div class="mx-auto flex max-w-xl items-center gap-3">
                <div class="min-w-0 shrink-0">
                    <p class="text-lg font-extrabold leading-tight tabular-nums text-slate-900">@if (! $selected && $product->choicePricesDiffer())<span class="text-xs font-medium text-slate-500">{{ $t('shop.card.from') }} </span>@endif{{ $currencySymbol }}{{ number_format($priceNow, 2) }}</p>
                    @if ($inCartQty > 0)
                        <a href="{{ route('customer.cart') }}" wire:navigate class="text-xs font-semibold text-blue-700">{{ $t('shop.product.in_bag', ['count' => $inCartQty]) }} · {{ $t('cart.bar_view') }}</a>
                    @elseif ($pct)
                        <p class="text-xs font-bold text-emerald-600">{{ $t('shop.product.percent_off', ['percent' => $pct]) }}</p>
                    @endif
                </div>
                <div class="grid flex-1 grid-cols-2 gap-2">
                    @if ($inCartQty > 0)
                        <div class="flex items-center justify-between rounded-xl border-2 border-blue-600 text-blue-700">
                            <button type="button" x-on:click="{!! $stickyLess !!}" class="min-h-11 px-3 text-lg font-bold" aria-label="{{ $t('shop.product.one_less') }}">−</button>
                            <span class="text-sm font-bold tabular-nums" aria-live="polite">{{ $inCartQty }}</span>
                            <button type="button" x-on:click="{!! $stickyAdd !!}" class="min-h-11 px-3 text-lg font-bold" aria-label="{{ $t('shop.product.one_more') }}">+</button>
                        </div>
                    @else
                        <button type="button" x-on:click="{!! $stickyAdd !!}" class="inline-flex min-h-11 items-center justify-center rounded-xl border-2 border-blue-600 bg-white px-2 text-sm font-bold text-blue-700">{{ $t('shop.product.add_to_bag') }}</button>
                    @endif
                    <button type="button" x-on:click="call('buyNow')" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-400 px-2 text-sm font-bold text-slate-900 shadow-sm">{{ $t('shop.product.buy_now') }}</button>
                </div>
            </div>
        </div>
    @endif

    <div x-show="! stuck">
        <livewire:customer.shop.cart-bar />
    </div>
    <livewire:customer.shop.choice-sheet />
</div>
