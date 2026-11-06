@php
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $money = fn ($v) => $currencySymbol.number_format((float) $v, 2);
    $open = $card['open'];
@endphp
{{-- Food F3: one restaurant's menu. Every word from SiteText (English + Telugu). --}}
<div class="mb-bottom-nav">
    {{-- Photo first on phones (2026-11-07): the restaurant card runs edge to edge under the header. --}}
    <div class="mx-auto max-w-3xl px-4 pb-6 sm:px-6 sm:pt-6">
        <x-customer.breadcrumbs class="hidden sm:block" :items="[
            ['label' => $city->name, 'url' => route('customer.city.show', $city->slug)],
            ['label' => $t('food.crumb'), 'url' => route('customer.food.home', $city->slug)],
            ['label' => $store->name, 'url' => null],
        ]" />

        <header class="relative -mx-4 overflow-hidden border-b border-slate-200 bg-white sm:mx-0 sm:mt-4 sm:rounded-3xl sm:border">
            {{-- ♡ + Share on the cover's corner (owner 2026-11-07); without a cover they sit above the rating --}}
            @if ($store->cover_url)
                <x-customer.item-actions :item="'store:'.$store->id" :title="$store->name" :image="$store->cover_url" class="absolute right-3 top-3 z-10" />
            @endif
            @if ($store->cover_url)
                <img src="{{ $store->cover_url }}" alt="" class="h-44 w-full object-cover sm:h-56" data-testid="restaurant-cover">
            @endif
            <div class="p-5">
            <div class="flex items-start gap-4">
                @if ($store->logo_url)
                    <img src="{{ $store->logo_url }}" alt="" class="h-20 w-20 shrink-0 rounded-2xl object-cover">
                @endif
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $store->name }}</h1>
                    @if ($card['cuisines'])<p class="text-sm text-slate-600">{{ $card['cuisines'] }}</p>@endif
                    @if ($store->address_line)<p class="text-xs text-slate-500">{{ $store->address_line }}</p>@endif
                </div>
                @if ($card['rating'] || ! $store->cover_url)
                <div class="flex shrink-0 flex-col items-end gap-2">
                @if (! $store->cover_url)
                    <x-customer.item-actions :item="'store:'.$store->id" :title="$store->name" :image="$store->logo_url" />
                @endif
                @if ($card['rating'])
                    <div class="shrink-0 rounded-xl border border-slate-200 px-2.5 py-1.5 text-center">
                        <p class="inline-flex rounded-md bg-emerald-600 px-1.5 py-0.5 text-xs font-bold leading-4 text-white" data-rating-chip>{{ $card['rating']['avg'] }} ★</p>
                        <p class="text-[11px] text-slate-500">{{ $card['rating']['n'] == 1 ? $t('food.rating_one') : $t('food.rating_many', ['count' => $card['rating']['n']]) }}</p>
                    </div>
                @endif
                </div>
                @endif
            </div>
            @if ($store->description)<p class="mt-3 text-sm text-slate-700">{{ $store->description }}</p>@endif
            <ul class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                @if ($open)
                    <li class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{{ $t('food.open_now') }} · {{ $t('food.cooks_in', ['minutes' => $card['minutes']]) }}</li>
                @else
                    <li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ $card['opensAt'] ?? $t('food.closed_now') }}</li>
                @endif
                <li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ $card['fee'] }}@if ($card['freeAbove']) · {{ $card['freeAbove'] }}@endif</li>
                @if ($card['minimum'])<li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ $card['minimum'] }}</li>@endif
                @if ($packaging)<li class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ $packaging }}</li>@endif
                @if ($card['pureVeg'])<li class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700"><x-food-mark type="veg" /> {{ $t('food.pure_veg') }}</li>@endif
            </ul>
            @if ($open && $card['busy'])
                <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $t('food.busy_note') }}</p>
            @endif
            </div>
        </header>

        @unless ($open)
            <p role="status" class="mt-4 rounded-xl bg-slate-100 px-4 py-3 text-sm font-medium text-slate-700" data-testid="restaurant-closed">{{ $t('food.closed_note') }}</p>
        @endunless

        @if ($message)
            <p role="status" class="mt-4 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $message }}</p>
        @endif

        <div class="sticky top-0 z-20 -mx-4 mt-4 flex items-center gap-2 bg-slate-50/95 px-4 py-2 backdrop-blur sm:mx-0 sm:rounded-2xl">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $t('food.menu.search_placeholder') }}" aria-label="{{ $t('food.menu.search_label') }}"
                   class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 text-base">
            <button type="button" wire:click="$toggle('vegOnly')" aria-pressed="{{ $vegOnly ? 'true' : 'false' }}"
                    @class(['inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl border px-3 text-sm font-semibold',
                            'border-emerald-600 bg-emerald-600 text-white' => $vegOnly, 'border-slate-300 bg-white text-slate-700' => ! $vegOnly])>
                <x-food-mark type="veg" /> {{ $t('food.menu.veg_only') }}
            </button>
        </div>

        @if ($sections->count() > 1)
            <nav aria-label="{{ $t('food.menu.sections') }}" class="mt-3 flex gap-2 overflow-x-auto pb-1">
                @foreach ($sections as $name => $items)
                    <a href="#sec-{{ $loop->index }}" class="shrink-0 rounded-full border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700">{{ $name !== '' ? $name : $t('food.menu.more') }} <span class="text-slate-400">{{ $items->count() }}</span></a>
                @endforeach
            </nav>
        @endif

        @forelse ($sections as $name => $items)
            <section id="sec-{{ $loop->index }}" class="mt-6 scroll-mt-20" wire:key="sec-{{ md5($name) }}">
                <h2 class="text-lg font-bold text-slate-900">{{ $name !== '' ? $name : $t('food.menu.more') }}</h2>
                <ul class="mt-2 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                    @foreach ($items as $d)
                        @php
                            $image = $d->image_urls[0] ?? null;
                            $customizable = $d->hasChoices() || $d->groups_count > 0;
                            $n = $qty[$d->id] ?? 0;
                        @endphp
                        <li id="dish-{{ $d->id }}" class="flex scroll-mt-24 gap-4 p-4" wire:key="dish-{{ $d->id }}" data-testid="dish">
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-1.5"><x-food-mark :type="$d->food_type ?: 'veg'" /><span class="font-semibold text-slate-900">{{ $d->name }}</span></p>
                                <p class="mt-1 text-sm font-bold text-slate-900">
                                    @if ($d->hasChoices() && $d->choicePricesDiffer())<span class="text-xs font-medium text-slate-500">{{ $t('food.menu.from') }}</span>@endif
                                    {{ $money($d->hasChoices() ? $d->fromPrice() : $d->effective_price) }}
                                    @if ($d->discount_percent && ! $d->hasChoices())
                                        <span class="ml-1 text-xs font-normal text-slate-400 line-through">{{ $money($d->price) }}</span>
                                        <span class="ml-1 text-xs font-bold text-emerald-700">{{ $t('shop.card.off_online', ['percent' => rtrim(rtrim(number_format((float) $d->discount_percent, 1), '0'), '.')]) }}</span>
                                    @endif
                                </p>
                                @if ($d->description)<p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ $d->description }}</p>@endif
                                @unless ($image)
                                    {{-- No photo: ♡ + Share under the words (owner 2026-11-07) --}}
                                    <x-customer.item-actions small :item="'product:'.$d->id" :title="$d->name.' · '.$store->name" :hash="'dish-'.$d->id" class="mt-2" />
                                @endunless
                            </div>
                            <div class="flex w-28 shrink-0 flex-col items-center">
                                @if ($image)
                                    <div class="relative">
                                        <img src="{{ $image }}" alt="{{ $d->name }}" loading="lazy" class="h-24 w-28 rounded-xl object-cover">
                                        {{-- ♡ + Share on the dish photo's corner (owner 2026-11-07) --}}
                                        <x-customer.item-actions small :item="'product:'.$d->id" :title="$d->name.' · '.$store->name" :image="$image" :hash="'dish-'.$d->id" class="absolute right-1 top-1 gap-1" />
                                    </div>
                                @endif
                                <div @class(['w-24', '-mt-4' => $image])>
                                    @if (! $open)
                                        <span class="flex min-h-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-400 shadow-sm">{{ $t('food.menu.add') }}</span>
                                    @elseif ($n > 0)
                                        <div class="flex items-center justify-between rounded-lg bg-[var(--mod)] text-white shadow-sm">
                                            <button type="button" wire:click="removeOne({{ $d->id }})" class="min-h-10 px-3 text-lg font-bold" aria-label="{{ $t('shop.card.one_less', ['item' => $d->name]) }}">−</button>
                                            <span class="text-sm font-bold tabular-nums" aria-live="polite">{{ $n }}</span>
                                            <button type="button" wire:click="add({{ $d->id }})" class="min-h-10 px-3 text-lg font-bold" aria-label="{{ $t('shop.card.one_more', ['item' => $d->name]) }}">+</button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="add({{ $d->id }})" data-testid="dish-add"
                                                class="min-h-10 w-full rounded-lg border-2 border-[var(--mod)] bg-white text-sm font-bold text-[var(--mod-ink)] shadow-sm hover:bg-[var(--mod-soft)]">{{ $t('food.menu.add') }}</button>
                                    @endif
                                </div>
                                @if ($customizable && $open)<p class="mt-1 text-[11px] text-slate-500">{{ $t('food.menu.customisable') }}</p>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                {{ ($search !== '' || $vegOnly) ? $t('food.menu.no_match') : $t('food.menu.empty') }}
            </p>
        @endforelse

        @if ($store->fssai_number)
            <p class="mt-8 text-xs text-slate-500">{{ $t('food.fssai', ['number' => $store->fssai_number]) }}</p>
        @endif
    </div>

    {{-- Choices sheet: portion and add-ons, each group with its rule. --}}
    @if ($sheetDish)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="sheet-title" wire:key="sheet-{{ $sheetDish->id }}"
             x-data x-on:keydown.escape.window="$wire.closeSheet()">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl">
                <div class="flex items-start justify-between gap-3">
                    <h2 id="sheet-title" class="flex items-center gap-1.5 text-lg font-bold text-slate-900"><x-food-mark :type="$sheetDish->food_type ?: 'veg'" /> {{ $sheetDish->name }}</h2>
                    <button type="button" wire:click="closeSheet" class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-600" aria-label="{{ $t('food.sheet.close') }}">✕</button>
                </div>

                @if ($sheetDish->hasChoices())
                    <fieldset class="mt-4">
                        <legend class="font-semibold text-slate-900">{{ $sheetDish->option_names[0] ?? $t('food.sheet.portion') }} <span class="ml-1 text-xs font-medium text-slate-500">{{ $t('food.addons.pick', ['count' => 1]) }}</span></legend>
                        <div class="mt-2 space-y-1">
                            @foreach ($sheetDish->activeVariants as $v)
                                @php $v->setRelation('product', $sheetDish); @endphp
                                <label class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50">
                                    <span class="flex items-center gap-2"><input type="radio" wire:model.live="variantId" value="{{ $v->id }}"> {{ $v->name }}</span>
                                    <span class="text-sm font-semibold">{{ $money($v->effectivePrice()) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                @foreach ($sheetGroups as $g)
                    <fieldset class="mt-4" wire:key="g-{{ $g->id }}">
                        <legend class="font-semibold text-slate-900">{{ $g->name }}
                            <span @class(['ml-1 text-xs font-medium', 'text-red-700' => $g->isRequired(), 'text-slate-500' => ! $g->isRequired()])>{{ \App\Services\Food\FoodCart::ruleText($g) }}</span>
                        </legend>
                        <div class="mt-2 space-y-1">
                            @php $picked = array_map('intval', (array) ($multi[$g->id] ?? [])); @endphp
                            @foreach ($g->addOns as $a)
                                {{-- At a "pick several" group's limit the other choices are greyed out and cannot be ticked. --}}
                                @php $full = $g->max_select !== 1 && $g->max_select && count($picked) >= $g->max_select && ! in_array($a->id, $picked, true); @endphp
                                <label @class(['flex min-h-11 items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50', 'cursor-pointer' => ! $full, 'cursor-not-allowed opacity-50' => $full])>
                                    <span class="flex items-center gap-2">
                                        @if ($g->max_select === 1)
                                            <input type="radio" wire:model.live="single.{{ $g->id }}" value="{{ $a->id }}">
                                        @else
                                            <input type="checkbox" wire:model.live="multi.{{ $g->id }}" value="{{ $a->id }}" class="rounded" @disabled($full)>
                                        @endif
                                        {{ $a->name }}
                                    </span>
                                    <span class="text-sm text-slate-700">{{ (float) $a->price > 0 ? '+ '.$money($a->price) : $t('food.sheet.free') }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                @if ($sheetError)
                    <p role="alert" class="mt-4 rounded-xl bg-red-50 px-3 py-2 text-sm font-medium text-red-800">{{ $sheetError }}</p>
                @endif

                <button type="button" wire:click="addCustomized" data-testid="sheet-add"
                        class="mt-5 flex min-h-12 w-full items-center justify-between rounded-2xl bg-blue-600 px-5 font-bold text-white hover:bg-blue-700">
                    <span>{{ $t('food.sheet.add') }}</span><span class="tabular-nums">{{ $money($sheetTotal) }}</span>
                </button>
            </div>
        </div>
    @endif

    {{-- One restaurant per food cart. --}}
    @if ($conflict)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center" role="alertdialog" aria-modal="true" aria-labelledby="conflict-title">
            <div class="w-full max-w-md rounded-t-3xl bg-white p-5 sm:rounded-3xl">
                <h2 id="conflict-title" class="text-lg font-bold text-slate-900">{{ $t('food.conflict.title') }}</h2>
                <p class="mt-2 text-sm text-slate-700">{{ $t('food.conflict.body', ['restaurant' => $conflict['inCart'], 'new' => $store->name]) }}</p>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="keepCart" class="min-h-12 rounded-2xl border border-slate-300 font-semibold text-slate-700">{{ $t('food.conflict.keep') }}</button>
                    <button type="button" wire:click="replaceCart" data-testid="conflict-replace" class="min-h-12 rounded-2xl bg-blue-600 font-bold text-white">{{ $t('food.conflict.replace') }}</button>
                </div>
            </div>
        </div>
    @endif

    <livewire:customer.shop.cart-bar />
</div>
