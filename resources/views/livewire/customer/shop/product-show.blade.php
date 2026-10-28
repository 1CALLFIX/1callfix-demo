<div class="mb-bottom-nav">
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => 'Shop', 'url' => route('customer.shop.home', $city->slug)],
            ['label' => $store->name, 'url' => route('customer.shop.store', [$city->slug, $store->slug])],
            ['label' => $product->name, 'url' => null],
        ]" />

        <div class="mt-4 grid gap-6 md:grid-cols-2">
            @php $images = $product->image_urls; @endphp
            <div x-data="{ shown: 0 }">
                <div class="aspect-square overflow-hidden rounded-3xl border border-slate-200 bg-white">
                    @forelse ($images as $i => $src)
                        <img src="{{ $src }}" alt="{{ $product->name }}" x-show="shown === {{ $i }}" @if ($i > 0) style="display:none" @endif class="h-full w-full object-contain">
                    @empty
                        <span class="flex h-full w-full items-center justify-center text-6xl font-bold text-slate-200">{{ mb_substr($product->name, 0, 1) }}</span>
                    @endforelse
                </div>
                @if (count($images) > 1)
                    <div class="mt-2 flex gap-2">
                        @foreach ($images as $i => $src)
                            <button type="button" x-on:click="shown = {{ $i }}" class="h-16 w-16 overflow-hidden rounded-xl border-2"
                                    x-bind:class="shown === {{ $i }} ? 'border-blue-600' : 'border-slate-200'" aria-label="Photo {{ $i + 1 }}">
                                <img src="{{ $src }}" alt="" class="h-full w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <a href="{{ route('customer.shop.store', [$city->slug, $store->slug]) }}" wire:navigate class="text-sm font-semibold uppercase tracking-wide text-blue-700">{{ $store->name }}</a>
                <h1 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $product->name }}</h1>
                <p class="mt-3 text-3xl font-bold">
                    {{ $currencySymbol }}{{ number_format($product->effective_price, 2) }}
                    @if ($product->discount_percent)
                        <span class="ml-2 text-base font-normal text-slate-400 line-through">{{ $currencySymbol }}{{ number_format((float) $product->price, 2) }}</span>
                    @endif
                </p>
                @if ($product->discount_percent)
                    <p class="mt-1 text-sm text-blue-700">{{ rtrim(rtrim(number_format((float) $product->discount_percent, 1), '0'), '.') }}% off when you pay online or from your wallet.</p>
                @endif
                <p @class(['mt-2 text-sm font-medium', 'text-emerald-700' => $product->stock > 5, 'text-amber-700' => $product->stock > 0 && $product->stock <= 5, 'text-red-700' => $product->stock <= 0])>
                    {{ $product->stock <= 0 ? 'Out of stock' : ($product->stock <= 5 ? 'Only '.$product->stock.' left' : 'In stock') }}
                </p>

                @if ($cartMessage)
                    <p role="status" class="mt-3 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
                @endif

                <div class="mt-5 max-w-xs">
                    @if ($product->stock <= 0)
                        <p class="rounded-xl bg-slate-100 py-3 text-center font-semibold text-slate-500">Out of stock</p>
                    @elseif (! $store->is_open)
                        <p class="rounded-xl bg-slate-100 py-3 text-center font-semibold text-slate-500">The shop is closed right now</p>
                    @elseif ($qty > 0)
                        <div class="flex items-center justify-between rounded-xl bg-blue-600 text-white">
                            <button type="button" wire:click="removeOne({{ $product->id }})" class="min-h-12 px-5 text-xl font-bold" aria-label="One less">−</button>
                            <span class="font-bold tabular-nums" aria-live="polite">{{ $qty }} in bag</span>
                            <button type="button" wire:click="addToCart({{ $product->id }})" class="min-h-12 px-5 text-xl font-bold" aria-label="One more">+</button>
                        </div>
                    @else
                        <button type="button" wire:click="addToCart({{ $product->id }})" class="min-h-12 w-full rounded-xl bg-blue-600 font-bold text-white hover:bg-blue-700">Add to bag</button>
                    @endif
                </div>

                @if ($product->description)
                    <div class="mt-6">
                        <h2 class="text-sm font-semibold text-slate-900">About this item</h2>
                        <p class="mt-1 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ $product->description }}</p>
                    </div>
                @endif

                <ul class="mt-6 space-y-1.5 rounded-2xl bg-slate-50 p-4 text-sm text-slate-700">
                    <li>Sold and packed by <span class="font-semibold">{{ $store->name }}</span>, {{ $city->name }}.</li>
                    <li>Delivered by a 1CallFix rider with a code you give at the door, or pick it up at the shop.</li>
                    <li>Damaged or wrong item? Return it within {{ \App\Support\MarketplaceSettings::get('marketplace.return_window_days') }} days of delivery.</li>
                </ul>
            </div>
        </div>

        @if ($more->isNotEmpty())
            <section class="mt-10">
                <h2 class="text-lg font-bold">More from {{ $store->name }}</h2>
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($more as $p)
                        <x-shop.product-card :product="$p" :city="$city" :qty="$inCart[$p->id] ?? 0" :currency-symbol="$currencySymbol" :show-store="false" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    <livewire:customer.shop.cart-bar />
</div>
