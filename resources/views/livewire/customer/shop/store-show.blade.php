<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => $city->name, 'url' => route('customer.city.show', $city->slug)],
            ['label' => 'Shop', 'url' => route('customer.shop.home', $city->slug)],
            ['label' => $store->name, 'url' => null],
        ]" />

        <header class="mt-4 flex flex-wrap items-start gap-4 rounded-3xl border border-slate-200 bg-white p-5">
            @if ($store->logo_url)
                <img src="{{ $store->logo_url }}" alt="" class="h-20 w-20 rounded-2xl object-cover">
            @else
                <span class="flex h-20 w-20 items-center justify-center rounded-2xl bg-blue-50 text-3xl font-bold text-blue-700">{{ mb_substr($store->name, 0, 1) }}</span>
            @endif
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold tracking-tight">{{ $store->name }}</h1>
                <p class="text-sm text-slate-500">{{ $store->address_line }}</p>
                @if ($store->description)<p class="mt-1 text-sm text-slate-700">{{ $store->description }}</p>@endif
                <ul class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                    <li @class(['rounded-full px-3 py-1', 'bg-emerald-50 text-emerald-700' => $store->is_open, 'bg-slate-100 text-slate-500' => ! $store->is_open])>{{ $store->is_open ? 'Open now' : 'Closed now' }}</li>
                    @if ($rating && $rating->n > 0)
                        <li class="rounded-full bg-amber-50 px-3 py-1 text-amber-800">★ {{ number_format((float) $rating->avg, 1) }} · {{ $rating->n }} {{ $rating->n == 1 ? 'rating' : 'ratings' }}</li>
                    @endif
                    <li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">
                        @if ((float) $store->delivery_fee_flat > 0)
                            Delivery {{ $currencySymbol }}{{ number_format((float) $store->delivery_fee_flat, 0) }}
                            @if ($store->free_delivery_above_amount !== null) · free above {{ $currencySymbol }}{{ number_format((float) $store->free_delivery_above_amount, 0) }} when paid online @endif
                        @else
                            Free delivery
                        @endif
                    </li>
                    @if ((float) $store->minimum_order_amount > 0)
                        <li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">Minimum order {{ $currencySymbol }}{{ number_format((float) $store->minimum_order_amount, 0) }}</li>
                    @endif
                    <li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">Pickup available</li>
                </ul>
            </div>
        </header>

        @if ($cartMessage)
            <p role="status" class="mt-4 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
        @endif

        <div class="mt-5 flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search {{ $store->name }}" aria-label="Search this shop"
                   class="min-h-11 w-full rounded-xl border border-slate-300 px-4 text-base sm:w-80">
            @foreach ($categories as $c)
                <button type="button" wire:click="$set('categoryId', {{ $categoryId === $c->id ? 'null' : $c->id }})"
                        @class(['rounded-full border px-3 py-1.5 text-sm', 'border-blue-600 bg-blue-600 text-white' => $categoryId === $c->id, 'border-slate-300 bg-white text-slate-700' => $categoryId !== $c->id])>{{ $c->name }}</button>
            @endforeach
        </div>

        @if ($products->isEmpty())
            <p class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">No items here yet.</p>
        @else
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($products as $p)
                    <x-shop.product-card :product="$p" :city="$city" :qty="$inCart[$p->id] ?? 0" :currency-symbol="$currencySymbol" :show-store="false" />
                @endforeach
            </div>
            <div class="mt-4">{{ $products->links() }}</div>
        @endif
    </div>

    <livewire:customer.shop.cart-bar />
</div>
