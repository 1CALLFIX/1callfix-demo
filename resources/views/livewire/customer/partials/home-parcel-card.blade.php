{{-- Homepage parcel card (owner 2026-10-26). Included by home.blade.php at the position chosen in Admin → Homepage &
     sign-up, only where Parcel is switched on for the browsed zone. Title, subtitle, chips, button words, picture,
     colour and the price badge are Super Admin settings (App\Support\SiteControls); the price is the area's Parcel Settings base fare. --}}
@php
    $c = \App\Support\SiteControls::class;
    $look = $c::parcelCardClasses();
    $chips = $c::parcelChips();
    $fromPrice = $c::parcelShowPrice() && $parcelFrom !== null
        ? $currencySymbol.rtrim(rtrim(number_format((float) $parcelFrom, 2, '.', ''), '0'), '.')
        : null;
@endphp
<section aria-labelledby="parcel-heading" class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 sm:pt-10 lg:px-8">
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br p-5 shadow-lg ring-4 sm:p-8 {{ $look['card'] }}">
        <img src="{{ $c::parcelImageUrl() }}" alt="" aria-hidden="true" loading="lazy" decoding="async"
             class="pointer-events-none absolute -right-2 top-4 h-28 w-40 object-contain sm:right-8 sm:top-1/2 sm:h-44 sm:w-64 sm:-translate-y-1/2">

        <div class="relative max-w-[60%] sm:max-w-md">
            @if ($fromPrice)
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold {{ $look['price'] }}">from {{ $fromPrice }}</span>
            @endif
            <p class="mt-2 text-xs font-semibold uppercase tracking-widest {{ $look['sub'] }}">{{ $c::parcelSubtitle() }}</p>
            <h2 id="parcel-heading" class="text-3xl font-black uppercase italic tracking-tight sm:text-5xl">{{ $c::parcelTitle() }}</h2>
        </div>

        @if ($chips)
            <ul class="relative mt-14 flex flex-wrap gap-1.5 text-xs sm:mt-5 sm:text-sm" aria-label="What you can send">
                @foreach ($chips as $chip)
                    <li class="rounded-full px-2.5 py-1 {{ $look['chip'] }}">{{ $chip }}</li>
                @endforeach
            </ul>
        @endif

        <div @class(['relative grid grid-cols-2 gap-2 sm:flex sm:gap-3', 'mt-4' => $chips, 'mt-14 sm:mt-5' => ! $chips])>
            <a href="{{ route('customer.parcels.create') }}" wire:navigate
               class="rounded-xl px-4 py-2.5 text-center text-sm font-bold transition sm:px-6 {{ $look['primary'] }}">{{ $c::parcelSendLabel() }}</a>
            <a href="{{ route('customer.parcels.index') }}" wire:navigate
               class="rounded-xl px-4 py-2.5 text-center text-sm font-semibold ring-1 transition sm:px-6 {{ $look['secondary'] }}">{{ $c::parcelTrackLabel() }}</a>
        </div>
    </div>
</section>
