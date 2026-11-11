<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $field = 'mt-1 block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600';
    $chip = 'inline-flex min-h-10 items-center rounded-full border px-3 text-sm font-medium transition';
?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <span>{{ $t('hotel.crumb') }}</span>
    </nav>
    <div class="mt-2 flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-blue-600">{{ $t('hotel.home.kicker', ['city' => $city->name]) }}</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('hotel.home.title') }}</h1>
        </div>
        <x-customer.share-button :title="$t('hotel.meta.title', ['city' => $city->name])" />
    </div>

    {{-- ── search ── --}}
    <div class="mt-5 grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-4" data-testid="hotel-search">
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.check_in') }}
            <input type="date" wire:model.live="checkIn" value="{{ $in }}" min="{{ now()->toDateString() }}" class="{{ $field }}">
        </label>
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.check_out') }}
            <input type="date" wire:model.live="checkOut" value="{{ $out }}" min="{{ $in }}" class="{{ $field }}">
        </label>
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.rooms') }}
            <select wire:model.live="rooms" class="{{ $field }}">
                @for ($i = 1; $i <= $maxRooms; $i++) <option value="{{ $i }}">{{ $i }}</option> @endfor
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">{{ $t('hotel.search.guests') }}
            <select wire:model.live="adults" class="{{ $field }}">
                @for ($i = 1; $i <= max(10, $maxRooms * 3); $i++) <option value="{{ $i }}">{{ $i }}</option> @endfor
            </select>
        </label>
    </div>

    {{-- ── sort + filters ── --}}
    <div class="mt-4 flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Sort">
        @foreach (['featured' => 'hotel.sort.featured', 'price' => 'hotel.sort.price', 'rating' => 'hotel.sort.rating'] as $code => $key)
            <button type="button" wire:click="$set('sort', '{{ $code }}')" @class([$chip, 'border-blue-600 bg-blue-600 text-white' => $sort === $code, 'border-slate-300 bg-white text-slate-700' => $sort !== $code])>{{ $t($key) }}</button>
        @endforeach
        <button type="button" wire:click="$toggle('breakfast')" @class([$chip, 'border-blue-600 bg-blue-50 text-blue-700' => $breakfast, 'border-slate-300 bg-white text-slate-700' => ! $breakfast])>{{ $t('hotel.filter.breakfast') }}</button>
        <select wire:model.live="maxPrice" aria-label="{{ $t('hotel.filter.max_price') }}" class="{{ $chip }} border-slate-300 bg-white pr-8 text-slate-700">
            <option value="0">{{ $t('hotel.filter.any_price') }}</option>
            @foreach ($priceSteps as $p) <option value="{{ $p }}">{{ $t('hotel.filter.up_to', ['amount' => '₹'.number_format($p)]) }}</option> @endforeach
        </select>
    </div>
    @if ($allFacilities !== [])
        <div class="mt-2 flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Facilities">
            @foreach ($allFacilities as $f)
                <label class="{{ $chip }} shrink-0 cursor-pointer border-slate-300 bg-white text-slate-700 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-700">
                    <input type="checkbox" value="{{ $f }}" wire:model.live="facilities" class="sr-only">{{ $f }}
                </label>
            @endforeach
        </div>
    @endif

    {{-- ── hotels ── --}}
    <ul class="mt-5 space-y-4" data-testid="hotel-cards">
        @forelse ($cards as $c)
            <li>
                <a href="{{ $c['url'] }}" wire:navigate @class(['flex flex-col overflow-hidden rounded-2xl border bg-white shadow-sm transition hover:shadow-md sm:flex-row', 'border-slate-200' => $c['available'], 'border-slate-200 opacity-70' => ! $c['available']])>
                    <div class="relative aspect-[3/2] w-full shrink-0 bg-slate-100 sm:aspect-auto sm:w-64">
                        @if ($c['photo']) <img src="{{ $c['photo'] }}" alt="{{ $c['hotel']->name }}" class="h-full w-full object-cover" loading="lazy"> @endif
                        @if ($c['featured']) <span class="absolute left-2 top-2 rounded-full bg-amber-400 px-2 py-0.5 text-xs font-bold text-slate-900">{{ $t('hotel.card.featured') }}</span> @endif
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold leading-tight">{{ $c['hotel']->name }}</h2>
                                <p class="text-sm text-slate-500">{{ $c['type'] }} · {{ \Illuminate\Support\Str::limit($c['hotel']->address_line, 60) }}</p>
                            </div>
                            @if ($c['rating'])
                                <span class="shrink-0 rounded-lg bg-emerald-600 px-2 py-1 text-sm font-bold text-white">★ {{ $t('hotel.card.rating', ['avg' => $c['rating']['avg'], 'n' => $c['rating']['n']]) }}</span>
                            @endif
                        </div>
                        @if ($c['facilities'] !== [])
                            <p class="text-xs text-slate-600">{{ implode(' · ', array_slice($c['facilities'], 0, 4)) }}</p>
                        @endif
                        <div class="mt-auto flex items-end justify-between gap-3">
                            <div class="text-xs">
                                @if ($c['cancel']) <p class="font-semibold text-emerald-700">✓ {{ $c['cancel'] }}</p> @endif
                                @if ($c['left']) <p class="font-semibold text-red-600">{{ $c['left'] }}</p> @endif
                            </div>
                            @if ($c['available'])
                                <div class="text-right">
                                    <p class="text-xl font-bold">{{ $c['perNightText'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $c['priceNote'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $c['totalText'] }}</p>
                                </div>
                            @else
                                <p class="text-sm font-semibold text-slate-500">{{ $t('hotel.card.full') }}</p>
                            @endif
                        </div>
                    </div>
                </a>
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">{{ $t('hotel.empty') }}</li>
        @endforelse
    </ul>
</div>
