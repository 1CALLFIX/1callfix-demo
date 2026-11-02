@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
{{-- Choice pop-up on the shop cards (owner 2026-11-02, Swiggy / Zepto style): a panel that slides up from the bottom
     on a phone (a centred box on a big screen) — photo, price, the sizes / colours as chips, Add to Cart. The customer
     stays on the shop page. Every word from SiteText (English + Telugu). --}}
<div data-choice-sheet>
    @if ($product)
        @php
            $image = $selected?->imageUrl() ?? ($product->image_urls[0] ?? null);
            $priceNow = $selected ? $selected->effectivePrice() : $product->fromPrice();
            $priceWas = $product->discount_percent && $selected ? $selected->listPrice() : null;
            $pct = $product->discount_percent ? rtrim(rtrim(number_format((float) $product->discount_percent, 1), '0'), '.') : null;
            $soldOut = $selected && $selected->stock <= 0;
        @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="choice-sheet-title"
             x-data x-on:keydown.escape.window="$wire.close()" x-init="$nextTick(() => $el.querySelector('[data-sheet-focus]')?.focus())" data-testid="choice-sheet">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="close" aria-hidden="true"></div>
            <div class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-md sm:rounded-3xl">
                <div class="mx-auto mb-3 h-1.5 w-10 rounded-full bg-slate-200 sm:hidden" aria-hidden="true"></div>
                <button type="button" wire:click="close" data-sheet-focus class="absolute right-3 top-3 grid h-10 w-10 place-items-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="{{ $t('shop.sheet.close') }}">
                    <x-icon name="x-mark" class="h-5 w-5" />
                </button>

                <div class="flex gap-3 pr-10">
                    <div class="h-20 w-20 shrink-0 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                        @if ($image)<img src="{{ $image }}" alt="" class="h-full w-full object-cover">
                        @else<span class="flex h-full w-full items-center justify-center text-2xl font-bold text-slate-300">{{ mb_substr($product->name, 0, 1) }}</span>@endif
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $product->store?->name }}</p>
                        <h2 id="choice-sheet-title" class="line-clamp-2 font-bold text-slate-900">{{ $product->name }}</h2>
                        <p class="mt-1 flex flex-wrap items-baseline gap-x-1.5">
                            @if (! $selected && $product->choicePricesDiffer())<span class="text-xs text-slate-500">{{ $t('shop.card.from') }}</span>@endif
                            <span class="text-lg font-extrabold text-slate-900" data-testid="sheet-price">{{ $currencySymbol }}{{ number_format($priceNow, 2) }}</span>
                            @if ($priceWas !== null)<span class="text-xs text-slate-400 line-through">{{ $currencySymbol }}{{ number_format($priceWas, 2) }}</span>@endif
                            @if ($pct)<span class="text-xs font-bold text-emerald-600">{{ $t('shop.product.percent_off', ['percent' => $pct]) }}</span>@endif
                        </p>
                        @if ($product->unitLabel())<p class="text-xs text-slate-500">{{ $t('shop.product.per_unit', ['unit' => $product->unitLabel()]) }}</p>@endif
                    </div>
                </div>

                @foreach ($choices as $name => $values)
                    @php $missing = $missingChoice === $name; @endphp
                    <div wire:key="sheet-choice-{{ md5($name) }}" @class(['mt-4 rounded-2xl border p-3 transition', 'border-red-300 bg-red-50' => $missing, 'border-slate-200' => ! $missing])>
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
                    <p role="status" class="mt-3 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $cartMessage }}</p>
                @endif

                <div class="mt-5">
                    @if (! $product->store?->is_open)
                        <p class="rounded-2xl bg-slate-100 py-3.5 text-center font-semibold text-slate-500">{{ $t('shop.product.shop_closed') }}</p>
                    @elseif ($soldOut)
                        <p class="rounded-2xl bg-slate-100 py-3.5 text-center font-semibold text-slate-500">{{ $t('shop.product.choice_sold_out', ['choice' => $selected->name]) }}</p>
                    @elseif ($choiceQty > 0)
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex items-center justify-between rounded-2xl border-2 border-blue-600 text-blue-700">
                                <button type="button" wire:click="removeChoiceOne" class="min-h-12 px-4 text-xl font-bold" aria-label="{{ $t('shop.product.one_less') }}">−</button>
                                <span class="text-sm font-bold tabular-nums" aria-live="polite" data-testid="sheet-in-cart">{{ $t('shop.product.in_bag', ['count' => $choiceQty]) }}</span>
                                <button type="button" wire:click="addChoice" class="min-h-12 px-4 text-xl font-bold" aria-label="{{ $t('shop.product.one_more') }}">+</button>
                            </div>
                            <button type="button" wire:click="close" class="min-h-12 rounded-2xl bg-blue-600 font-bold text-white hover:bg-blue-700">{{ $t('shop.sheet.done') }}</button>
                        </div>
                    @else
                        <button type="button" wire:click="addChoice" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 font-bold text-white shadow-sm hover:bg-blue-700" data-testid="sheet-add">
                            <x-icon name="shopping-cart" class="h-5 w-5" /> {{ $t('shop.product.add_to_bag') }}@if ($selected) · {{ $currencySymbol }}{{ number_format($selected->effectivePrice(), 2) }}@endif
                        </button>
                    @endif
                </div>

                @if ($productUrl)
                    <a href="{{ $productUrl }}" wire:navigate class="mt-3 block text-center text-sm font-semibold text-blue-700 hover:underline">{{ $t('shop.sheet.details') }}</a>
                @endif
            </div>
        </div>
    @endif
</div>
