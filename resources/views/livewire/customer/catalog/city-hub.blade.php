{{-- City URL architecture (2026-11-01): /{city}, the city hub. One door per business live here (Admin → Modules). --}}
@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => $t('city.crumb_home'), 'url' => route('customer.home')],
            ['label' => $city->name, 'url' => null],
        ]" />

        <header class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $t('city.hub.title', ['brand' => $brand, 'city' => $city->name]) }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">{{ $t('city.hub.intro', ['city' => $city->name]) }}</p>
        </header>

        @if ($doors)
            <ul class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4" data-testid="city-hub-doors">
                @foreach ($doors as $door)
                    <li wire:key="hub-door-{{ $door['key'] }}">
                        <a href="{{ $door['url'] }}" wire:navigate data-door="{{ $door['key'] }}"
                           class="group flex h-full flex-col items-center rounded-3xl border border-slate-200 bg-white p-4 text-center shadow-sm shadow-slate-900/5 transition duration-200 hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-lg hover:shadow-blue-600/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            <span class="grid aspect-square w-full max-w-28 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-blue-50 to-sky-50">
                                <img src="{{ $door['image'] }}" alt="" width="112" height="112" loading="eager" decoding="async"
                                     @class(['h-full w-full transition duration-300 group-hover:scale-105', \App\Support\SiteControls::imageFit() === 'cover' ? 'object-cover' : 'object-contain'])>
                            </span>
                            <span class="mt-2 text-base font-bold text-slate-900">{{ $door['title'] }}</span>
                            <span class="mt-0.5 text-xs leading-snug text-slate-500">{{ $door['line'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">{{ $t('city.hub.empty', ['city' => $city->name]) }}</p>
        @endif
    </div>
</div>
