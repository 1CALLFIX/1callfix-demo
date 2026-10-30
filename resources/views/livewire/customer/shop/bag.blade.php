@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
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
            <p role="status" class="mt-3 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $message }}</p>
        @endif

        @forelse ($groups as $g)
            @php $store = $g['store']; $citySlug = $store->franchise?->city?->slug; @endphp
            <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-4 sm:p-5" wire:key="bag-{{ $store->id }}">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-bold">
                        @if ($citySlug)<a href="{{ route('customer.shop.store', [$citySlug, $store->slug]) }}" wire:navigate class="hover:underline">{{ $store->name }}</a>@else{{ $store->name }}@endif
                    </h3>
                    @unless ($store->is_open)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ $t('cart.closed_now') }}</span>@endunless
                </div>
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($g['lines'] as $line)
                        @php $p = $line->product; $gone = ! $p || ! $p->is_active || ! $p->is_approved; @endphp
                        <li class="flex items-center gap-3 py-3" wire:key="line-{{ $line->id }}">
                            <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-slate-50">
                                @if ($p && ($img = $p->image_urls[0] ?? null))<img src="{{ $img }}" alt="" loading="lazy" class="h-full w-full object-cover">@endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ $p?->name ?? $t('cart.item_removed') }}@if ($line->variant) <span class="font-normal text-slate-500">({{ $line->variant->name }})</span>@endif</p>
                                @if ($gone)
                                    <p class="text-xs font-semibold text-red-700">{{ $t('cart.item_gone') }}</p>
                                @else
                                    <p class="text-sm text-slate-600">{{ $currencySymbol }}{{ number_format((float) ($line->variant ? $line->variant->effectivePrice() : $p->effective_price), 2) }}</p>
                                @endif
                            </div>
                            @unless ($gone)
                                <div class="flex items-center rounded-lg border border-slate-300">
                                    <button type="button" wire:click="change({{ $line->id }}, -1)" class="min-h-9 px-3 font-bold" aria-label="{{ $t('cart.one_less') }}">−</button>
                                    <span class="min-w-6 text-center text-sm font-bold tabular-nums">{{ $line->quantity }}</span>
                                    <button type="button" wire:click="change({{ $line->id }}, 1)" class="min-h-9 px-3 font-bold" aria-label="{{ $t('cart.one_more') }}">+</button>
                                </div>
                            @endunless
                            <button type="button" wire:click="removeLine({{ $line->id }})" class="text-xs font-medium text-slate-500 hover:text-red-700">{{ $t('cart.remove') }}</button>
                        </li>
                    @endforeach
                </ul>
                @if ($g['freeAbove'] !== null)
                    @php $toFree = max(0, $g['freeAbove'] - $g['subtotal']); @endphp
                    <div class="mt-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800" data-testid="free-delivery-progress">
                        {{ $toFree > 0 ? $t('cart.free_delivery_short', ['amount' => $currencySymbol.number_format($toFree, 2)]) : $t('cart.free_delivery_ok') }}
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-emerald-100" aria-hidden="true">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ (int) min(100, round($g['subtotal'] / $g['freeAbove'] * 100)) }}%"></div>
                        </div>
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                    <p class="text-sm">{{ $t('cart.items_total') }}: <span class="font-bold">{{ $currencySymbol }}{{ number_format($g['subtotal'], 2) }}</span>
                        @if ($g['short'] > 0)<span class="block text-xs text-amber-700">{{ $t('cart.min_short', ['amount' => $currencySymbol.number_format($g['short'], 2)]) }}</span>@endif
                    </p>
                    @if ($g['canCheckout'] && $g['short'] <= 0)
                        <a href="{{ route('customer.shop.checkout', $store->id) }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700">{{ $t('cart.checkout_shop', ['shop' => $store->name]) }}</a>
                    @else
                        <span class="inline-flex min-h-11 items-center rounded-xl bg-slate-200 px-5 text-sm font-bold text-slate-500">{{ $t('cart.checkout_off') }}</span>
                    @endif
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
