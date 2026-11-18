<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.vehicles.home', $city->slug) }}" wire:navigate class="hover:underline">{{ $t('vehicles.crumb') }}</a> ›
        <span>{{ $vehicle->title }}</span>
    </nav>

    <x-customer.photo-gallery class="mt-3" :photos="$photos" :title="$vehicle->title" :item="'vehicle:'.$vehicle->id" :cover="$card['cover']" />

    <div class="mt-5 grid gap-6 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <div>
                <p class="text-sm font-semibold text-sky-700">{{ $card['what'] }}</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $vehicle->title }}</h1>
                @if ($card['where']) <p class="mt-1 text-sm text-slate-600">📍 {{ $card['where'] }}</p> @endif
            </div>

            @if ($vehicle->driver_options)
                <div class="flex flex-wrap gap-2" data-testid="vehicle-how">
                    @foreach ($vehicle->driver_options as $h) <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">🚘 {{ $h }}</span> @endforeach
                </div>
            @endif

            <ul class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3" data-testid="vehicle-facts">
                @foreach ($facts as [$label, $value, $tone])
                    <li class="rounded-xl p-3 {{ $tone }}"><p class="text-xs opacity-80">{{ $label }}</p><p class="font-semibold">{{ $value }}</p></li>
                @endforeach
            </ul>

            @if ($vehicle->features)
                <section data-testid="vehicle-features">
                    <h2 class="text-lg font-semibold">{{ $t('vehicles.show.features') }}</h2>
                    <ul class="mt-2 grid gap-1.5 text-sm text-slate-800 sm:grid-cols-2">
                        @foreach ($vehicle->features as $f) <li class="flex items-start gap-2"><span class="text-emerald-600" aria-hidden="true">&check;</span> {{ $f }}</li> @endforeach
                    </ul>
                </section>
            @endif

            @if ($vehicle->rule_list)
                <section data-testid="vehicle-rules">
                    <h2 class="text-lg font-semibold">{{ $t('vehicles.show.rules') }}</h2>
                    <ul class="mt-2 space-y-1.5 text-sm text-slate-800">
                        @foreach ($vehicle->rule_list as $r) <li class="flex items-start gap-2"><span class="text-rose-600" aria-hidden="true">•</span> {{ $r }}</li> @endforeach
                    </ul>
                </section>
            @endif

            @if ($vehicle->about)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('vehicles.show.about') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $vehicle->about }}</p>
                </section>
            @endif

            <p class="rounded-xl bg-slate-100 px-3 py-2 text-sm text-slate-700">🤝 {{ $t('vehicles.show.deal_note') }}</p>
        </div>

        <aside class="lg:col-span-1">
            <div class="sticky top-20">
                <livewire:customer.vehicles.contact :listing-id="$vehicle->id" :key="'vcontact-'.$vehicle->id" />
            </div>
        </aside>
    </div>
</div>
