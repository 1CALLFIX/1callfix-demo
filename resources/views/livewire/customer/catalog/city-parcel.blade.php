{{-- City URL architecture (2026-11-01): /{city}/parcel. Every word from Website text & language (City pages). --}}
@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => $t('city.crumb_home'), 'url' => route('customer.home')],
            ['label' => $city->name, 'url' => route('customer.city.show', $city->slug)],
            ['label' => $t('city.crumb_parcel'), 'url' => null],
        ]" />

        <header class="mt-4 flex flex-col gap-5 rounded-3xl bg-gradient-to-br from-blue-600 to-blue-800 px-5 py-6 text-white sm:flex-row sm:items-center sm:px-8 sm:py-8">
            <div class="min-w-0 flex-1">
                @if ($price)
                    <p class="inline-block rounded-full bg-amber-400 px-3 py-0.5 text-xs font-bold text-slate-900">{{ $t('city.parcel.from', ['price' => $price]) }}</p>
                @endif
                <h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">{{ $t('city.parcel.title', ['city' => $city->name]) }}</h1>
                <p class="mt-1 max-w-xl text-sm text-blue-100">{{ $t('city.parcel.intro', ['brand' => $brand, 'city' => $city->name]) }}</p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ route('customer.parcels.create') }}" wire:navigate
                       class="inline-flex min-h-12 items-center rounded-xl bg-amber-400 px-5 text-sm font-bold text-slate-900 hover:bg-amber-300">{{ $t('city.parcel.send') }}</a>
                    <a href="{{ route('customer.parcels.index') }}" wire:navigate
                       class="inline-flex min-h-12 items-center rounded-xl border border-white/40 px-5 text-sm font-semibold text-white hover:bg-white/10">{{ $t('city.parcel.mine') }}</a>
                </div>
            </div>
            <img src="{{ $icon }}" alt="" width="160" height="160" class="h-32 w-32 shrink-0 self-center object-contain sm:h-40 sm:w-40">
        </header>
    </div>
</div>
