@props([
    'item',
    'counts' => [],
    'currencySymbol' => '₹',
    'accent' => 'bg-blue-600 hover:bg-blue-700',
])

{{--
    One row on a subcategory page with sections (owner 2026-11-14, the Urban Company way): photo, name, price and a
    big Add button. A grouped service (Split AC Service) shows its choice buttons; the price, the Add button and the
    stepper follow the picked choice. Every choice is its own real service: Add puts THAT service in the cart.
    No arithmetic here; prices come from CatalogPresenter, the same numbers the cart and checkout use.
--}}

@php
    $main = $item['card'];
    $choices = $item['choices'];
    $options = $choices ?: [['id' => $main['service']->id, 'label' => $main['name'], 'card' => $main]];
@endphp

<article x-data="{ pick: {{ $options[0]['id'] }} }" data-testid="section-item"
         class="flex gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <a href="{{ $main['url'] }}" wire:navigate class="shrink-0">
        @if ($main['image_url'])
            <img src="{{ $main['image_url'] }}" alt="" loading="lazy" decoding="async" width="96" height="96"
                 class="h-20 w-20 rounded-xl object-cover sm:h-24 sm:w-24">
        @else
            <span class="flex h-20 w-20 items-center justify-center rounded-xl bg-slate-100 text-2xl font-bold text-slate-400 sm:h-24 sm:w-24">{{ mb_substr($main['name'], 0, 1) }}</span>
        @endif
    </a>

    <div class="min-w-0 flex-1">
        <h3 class="text-base font-semibold text-slate-900">{{ $main['name'] }}</h3>
        <x-customer.rating :rating="$main['rating']" :count="$main['review_count']" class="mt-1" />

        @if ($choices)
            <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="{{ \App\Support\SiteText::get('service.choose_type') }}">
                @foreach ($choices as $choice)
                    <button type="button" x-on:click="pick = {{ $choice['id'] }}"
                            x-bind:aria-pressed="pick === {{ $choice['id'] }} ? 'true' : 'false'"
                            x-bind:class="pick === {{ $choice['id'] }} ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-700'"
                            class="inline-flex min-h-[44px] items-center rounded-full border px-4 text-sm font-semibold transition">
                        {{ $choice['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        @foreach ($options as $option)
            @php $id = $option['id']; $qty = (int) ($counts[$id] ?? 0); @endphp
            <div x-show="pick === {{ $id }}" @if (! $loop->first) style="display: none" @endif
                 class="mt-3 flex flex-wrap items-center justify-between gap-3" wire:key="section-opt-{{ $id }}">
                <div>
                    <x-customer.price :card="$option['card']" :currency-symbol="$currencySymbol" />
                    @if ($option['card']['duration_mins'])
                        <p class="mt-0.5 text-xs text-slate-500">⏱ {{ $option['card']['duration_mins'] }} min</p>
                    @endif
                    <a href="{{ $option['card']['url'] }}" wire:navigate class="mt-1 inline-block text-xs font-semibold text-blue-700 hover:underline">{{ \App\Support\SiteText::get('service.section.details') }}</a>
                </div>

                @if ($qty > 0)
                    <div class="inline-flex items-center rounded-xl border-2 border-emerald-600 bg-emerald-50" data-testid="section-stepper">
                        <button type="button" wire:click="removeOne({{ $id }})" wire:loading.attr="disabled"
                                class="h-11 w-11 text-xl font-bold text-emerald-700" aria-label="Remove one">−</button>
                        <span class="min-w-[2rem] text-center text-base font-bold text-emerald-800">{{ $qty }}</span>
                        <button type="button" wire:click="addService({{ $id }})" wire:loading.attr="disabled"
                                class="h-11 w-11 text-xl font-bold text-emerald-700" aria-label="Add one more">+</button>
                    </div>
                @else
                    <button type="button" wire:click="addService({{ $id }})" wire:loading.attr="disabled"
                            class="inline-flex min-h-[44px] min-w-[96px] items-center justify-center rounded-xl px-5 text-sm font-bold text-white shadow-sm transition {{ $accent }}">
                        {{ \App\Support\SiteText::get('service.section.add') }}
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</article>
