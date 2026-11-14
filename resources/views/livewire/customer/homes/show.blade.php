<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <nav aria-label="Breadcrumb" class="text-xs text-slate-500">
        <a href="{{ route('customer.city.show', $city->slug) }}" wire:navigate class="hover:underline">{{ $city->name }}</a> ›
        <a href="{{ route('customer.homes.home', $city->slug) }}" wire:navigate class="hover:underline">{{ $t('homes.crumb') }}</a> ›
        <span>{{ $home->title }}</span>
    </nav>

    <x-customer.photo-gallery class="mt-3" :photos="$photos" :title="$home->title" :item="'home:'.$home->id" :cover="$card['cover']" />

    <div class="mt-5 grid gap-6 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <div>
                <p class="text-sm font-semibold text-emerald-700">{{ $card['what'] }}</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $home->title }}</h1>
                @if ($card['where']) <p class="mt-1 text-sm text-slate-600">📍 {{ $card['where'] }}</p> @endif
            </div>

            <ul class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3" data-testid="home-facts">
                @foreach ($facts as [$label, $value, $tone])
                    <li class="rounded-xl p-3 {{ $tone }}"><p class="text-xs opacity-80">{{ $label }}</p><p class="font-semibold">{{ $value }}</p></li>
                @endforeach
            </ul>

            @if ($home->tenants)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('homes.show.for') }}</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($home->tenants as $who) <span class="rounded-full bg-violet-100 px-3 py-1 text-sm font-medium text-violet-800">{{ $who }}</span> @endforeach
                    </div>
                </section>
            @endif

            @if ($facilityGroups !== [])
                <section data-testid="home-facilities">
                    <h2 class="text-lg font-semibold">{{ $t('hall.show.facilities') }}</h2>
                    @php $tones = ['bg-sky-50 text-sky-900', 'bg-amber-50 text-amber-900', 'bg-emerald-50 text-emerald-900', 'bg-violet-50 text-violet-900', 'bg-pink-50 text-pink-900', 'bg-teal-50 text-teal-900']; @endphp
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach ($facilityGroups as $gi => $g)
                            <div class="rounded-xl p-3 {{ $tones[$gi % count($tones)] }}">
                                @if ($g['title'] !== '') <p class="text-sm font-semibold">{{ $g['title'] }}</p> @endif
                                <ul class="mt-1.5 space-y-1 text-sm text-slate-800">
                                    @foreach ($g['items'] as $f) <li class="flex items-start gap-2"><span class="text-emerald-600" aria-hidden="true">&check;</span> {{ $f }}</li> @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($home->rule_list)
                <section data-testid="home-rules">
                    <h2 class="text-lg font-semibold">{{ $t('homes.show.rules') }}</h2>
                    <ul class="mt-2 space-y-1.5 text-sm text-slate-800">
                        @foreach ($home->rule_list as $r) <li class="flex items-start gap-2"><span class="text-rose-600" aria-hidden="true">•</span> {{ $r }}</li> @endforeach
                    </ul>
                </section>
            @endif

            @if ($home->about)
                <section>
                    <h2 class="text-lg font-semibold">{{ $t('homes.show.about') }}</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $home->about }}</p>
                </section>
            @endif

            <p class="rounded-xl bg-slate-100 px-3 py-2 text-sm text-slate-700">🔒 {{ $t('homes.show.address_note') }}</p>
        </div>

        <aside class="lg:col-span-1">
            <div class="sticky top-20">
                <livewire:customer.homes.contact :listing-id="$home->id" :key="'contact-'.$home->id" />
            </div>
        </aside>
    </div>
</div>
