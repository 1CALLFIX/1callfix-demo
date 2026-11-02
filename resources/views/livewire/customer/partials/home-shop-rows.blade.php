{{--
    City URL architecture (2026-11-01): the homepage shop rows, like the service rows. $row is one of shop_categories,
    shops, shop_products. Data from Home::shopRows(): only where the shop is on in the visitor's city, only real shops,
    and a row with nothing to show is not drawn. Words: Website text & language (Homepage).
--}}
@php
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $city = $shop['city'];
@endphp
@if ($city)
    @switch($row)
        @case('shop_categories')
            @if ($shop['categories']->isNotEmpty())
                <section aria-labelledby="shop-cats-heading" class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8" data-testid="home-shop-categories">
                    <x-customer.section-heading id="shop-cats-heading" :title="$t('home.shop_categories.title')"
                                                :link-label="$t('home.see_all')" :link="route('customer.shop.home', $city->slug)" />
                    <ul class="mt-5 grid snap-x grid-flow-col auto-cols-[5.5rem] justify-start gap-3 overflow-x-auto pb-2 sm:auto-cols-[7rem]">
                        @foreach ($shop['categories'] as $cat)
                            <li class="snap-start">
                                <a href="{{ $cat['url'] }}" wire:navigate
                                   class="group flex h-full flex-col items-center gap-2 rounded-2xl p-1 text-center hover:bg-blue-50/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                    <span aria-hidden="true" class="grid h-16 w-16 place-items-center overflow-hidden rounded-2xl bg-blue-50 sm:h-20 sm:w-20">
                                        @if ($cat['image'])
                                            <img src="{{ $cat['image'] }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
                                        @else
                                            <span class="text-xl font-bold text-blue-700">{{ mb_substr($cat['name'], 0, 1) }}</span>
                                        @endif
                                    </span>
                                    <span class="line-clamp-2 text-xs font-medium leading-tight text-slate-900 sm:text-sm">{{ $cat['name'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @break

        @case('shops')
            @if ($shop['stores']->isNotEmpty())
                <section aria-labelledby="shops-row-heading" class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8" data-testid="home-shops">
                    <x-customer.section-heading id="shops-row-heading" :title="$t('home.shops.title')"
                                                :description="$t('home.shops.line', ['city' => $city->name])"
                                                :link-label="$t('home.see_all')" :link="route('customer.shop.home', $city->slug)" />
                    <ul class="mt-5 grid snap-x grid-flow-col auto-cols-[16rem] justify-start gap-3 overflow-x-auto pb-2 sm:auto-cols-[20rem]">
                        @foreach ($shop['stores'] as $s)
                            <li class="snap-start">
                                <a href="{{ route('customer.shop.store', [$city->slug, $s->slug]) }}" wire:navigate
                                   class="flex h-full items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 hover:border-blue-300">
                                    @if ($s->logo_url)
                                        <img src="{{ $s->logo_url }}" alt="" loading="lazy" class="h-14 w-14 shrink-0 rounded-xl object-cover">
                                    @else
                                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl font-bold text-blue-700">{{ mb_substr($s->name, 0, 1) }}</span>
                                    @endif
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-900">{{ $s->name }}</span>
                                        <span class="block text-xs text-slate-500">{{ $s->products_count === 1 ? $t('shop.item_one') : $t('shop.item_many', ['count' => $s->products_count]) }}
                                            · @if ((float) $s->delivery_fee_flat > 0) {{ $t('shop.delivery_fee', ['amount' => $currencySymbol.number_format((float) $s->delivery_fee_flat, 0)]) }}@else {{ $t('shop.free_delivery') }} @endif</span>
                                        <span @class(['mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-50 text-emerald-700' => $s->is_open, 'bg-slate-100 text-slate-500' => ! $s->is_open])>{{ $s->is_open ? $t('shop.open_now') : $t('shop.closed') }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @break

        @case('shop_products')
            @if ($shop['products']->isNotEmpty())
                <section aria-labelledby="shop-products-heading" class="border-y border-slate-200 bg-slate-50" data-testid="home-shop-products">
                    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                        <x-customer.section-heading id="shop-products-heading" :title="$t('home.shop_products.title')"
                                                    :description="$t('home.shop_products.line', ['city' => $city->name])"
                                                    :link-label="$t('home.see_all')" :link="route('customer.shop.home', $city->slug)" />
                        @if ($cartMessage)
                            <p role="status" class="mt-4 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
                        @endif
                        <div class="mt-5 grid snap-x grid-flow-col auto-cols-[10rem] justify-start gap-3 overflow-x-auto pb-2 sm:auto-cols-[13rem]">
                            @foreach ($shop['products'] as $p)
                                <div class="snap-start">
                                    <x-shop.product-card :product="$p" :city="$city" :qty="$inCart[$p->id] ?? 0" :currency-symbol="$currencySymbol" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif
            @break
    @endswitch
@endif
