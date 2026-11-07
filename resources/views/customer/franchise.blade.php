@php
    use App\Support\InfoPages\ContactDetails as C;
    use App\Support\InfoPages\FranchisePage as F;
    $st = fn (string $key) => \App\Support\SiteText::get($key);
    $accepting = F::accepting();
    $applyUrl = F::applyUrl();
    $stats = array_merge($p['show_live_stats'] ? $liveStats : [], $p['stats']);
    $tel = $p['show_contact'] ? C::telHref() : null;
    $wa = $p['show_contact'] ? C::whatsappHref() : null;
    $mail = $p['show_contact'] ? (C::get('franchise_email') ?: C::get('email')) : null;
    $talkUrl = $wa ?: $tel;
    $doors = [
        ['services', 'wrench'], ['food', 'sparkles'], ['shop', 'shopping-bag'], ['parcel', 'map'],
    ];
    $schema = [];
    if ($p['faq']) {
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $p['faq'])];
    }
@endphp
{{-- Franchise page (owner 2026-11-07): every word, list, picture and switch from Admin → Website & Pages → Franchise
     page (Super Admin only), see App\Support\InfoPages\FranchisePage. Applying = the one /partners form, franchise card. --}}
<x-layouts.customer :title="$p['seo_title']" :raw-title="true" :meta-description="$p['seo_description']" :indexable="true" :schema="$schema" :og-image="$heroUrl">
<div class="mb-bottom-nav" data-franchise-page>

    {{-- ============ Hero ============ --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-slate-950 via-blue-950 to-blue-800 text-white">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 opacity-[0.07]" style="background-image:linear-gradient(#fff 1px,transparent 1px),linear-gradient(90deg,#fff 1px,transparent 1px);background-size:40px 40px"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-amber-300/20 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -bottom-32 left-10 h-80 w-80 rounded-full bg-blue-400/20 blur-3xl"></div>

        <div class="relative mx-auto grid max-w-7xl items-center gap-10 px-4 pb-20 pt-12 sm:px-6 sm:pt-16 lg:grid-cols-2 lg:px-8 lg:pb-28">
            <div>
                @if ($p['hero_badge'])
                    <p class="inline-flex items-center gap-1.5 rounded-full bg-amber-300/15 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-200 ring-1 ring-inset ring-amber-200/30">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 7l4.5 4L12 4l4.5 7L21 7l-2 12H5L3 7z"/></svg>
                        {{ $p['hero_badge'] }}
                    </p>
                @endif
                <h1 class="mt-4 text-4xl font-extrabold leading-[1.1] tracking-tight sm:text-5xl">{{ $p['hero_title'] }}</h1>
                @if ($p['hero_subtitle'])<p class="mt-5 max-w-xl text-base leading-relaxed text-blue-100 sm:text-lg">{{ $p['hero_subtitle'] }}</p>@endif

                <div class="mt-7 flex flex-wrap items-center gap-3">
                    @if ($accepting)
                        <a href="{{ $applyUrl }}" class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-amber-400 px-6 text-base font-bold text-slate-900 shadow-lg shadow-amber-500/20 transition hover:bg-amber-300" data-franchise-apply>
                            {{ $p['cta_label'] }}
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    @endif
                    @if ($talkUrl && $p['call_label'])
                        <a href="{{ $talkUrl }}" @if ($wa) target="_blank" rel="noopener" @endif class="inline-flex min-h-12 items-center gap-2 rounded-xl px-5 text-base font-semibold text-white ring-1 ring-inset ring-white/30 transition hover:bg-white/10" data-franchise-talk>
                            <x-icon :name="$wa ? 'chat' : 'phone'" class="h-5 w-5" /> {{ $p['call_label'] }}
                        </a>
                    @endif
                    <x-customer.share-button :title="$p['hero_title']" round />
                </div>

                @if ($p['highlights'])
                    <ul class="mt-7 flex flex-col gap-2 text-sm text-blue-50 sm:flex-row sm:flex-wrap sm:gap-x-6">
                        @foreach ($p['highlights'] as $h)
                            <li class="inline-flex items-center gap-2"><svg class="h-4 w-4 shrink-0 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>{{ $h['text'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="relative">
                @if ($heroUrl)
                    <img src="{{ $heroUrl }}" alt="" class="aspect-[4/3] w-full rounded-3xl object-cover shadow-2xl ring-1 ring-white/10" fetchpriority="high">
                @else
                    {{-- No picture uploaded: the multi-business idea itself, in each business's own colour. --}}
                    <div class="rounded-3xl bg-white/5 p-5 shadow-2xl ring-1 ring-inset ring-white/10 backdrop-blur-sm sm:p-6" aria-hidden="true">
                        <div class="grid grid-cols-2 gap-3 sm:gap-4">
                            @foreach ($doors as [$m, $icon])
                                <div class="rounded-2xl bg-white p-4 text-slate-900 shadow-lg" style="{{ \App\Support\ModuleTheme::style($m) }}">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-[var(--mod-soft)] text-[var(--mod)]"><x-icon :name="$icon" class="h-6 w-6" /></span>
                                    <p class="mt-3 text-base font-bold">{{ $st('home.door.'.$m.'.title') }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $st('home.door.'.$m.'.line') }}</p>
                                    <span class="mt-3 block h-1 w-10 rounded-full bg-[var(--mod)]"></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ============ Numbers strip (live from the app + the Super Admin's own figures) ============ --}}
    @if ($stats)
        <section class="relative z-10 mx-auto -mt-12 max-w-5xl px-4 sm:px-6 lg:px-8" aria-label="1CallFix in numbers">
            <dl @class(['grid divide-slate-100 rounded-3xl bg-white p-2 shadow-xl shadow-slate-900/10 ring-1 ring-slate-200', 'grid-cols-2 divide-x sm:grid-cols-4' => count($stats) >= 4, 'grid-cols-3 divide-x' => count($stats) === 3, 'grid-cols-2 divide-x' => count($stats) === 2, 'grid-cols-1' => count($stats) === 1]) data-franchise-stats>
                @foreach (array_slice($stats, 0, 8) as $s)
                    <div class="flex flex-col-reverse justify-end px-3 py-4 text-center">
                        <dt class="mt-1 text-xs text-slate-500 sm:text-sm">{{ $s['label'] }}</dt>
                        <dd class="text-2xl font-extrabold tracking-tight text-blue-700 sm:text-3xl">{{ $s['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    {{-- ============ Why ============ --}}
    @if ($p['why'])
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fr-why">
            <div class="max-w-2xl">
                <h2 id="fr-why" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $p['why_title'] }}</h2>
                @if ($p['why_intro'])<p class="mt-3 text-base text-slate-600">{{ $p['why_intro'] }}</p>@endif
            </div>
            <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($p['why'] as $w)
                    <li class="cf-card p-6">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-50 text-blue-700"><x-icon :name="$w['icon']" class="h-6 w-6" /></span>
                        <h3 class="mt-4 text-lg font-semibold text-slate-900">{{ $w['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $w['text'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ============ What you get + How you earn + Investment ============ --}}
    <section class="bg-slate-50" aria-labelledby="fr-get">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:px-8">
            @if ($p['support'])
                <div class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
                    <h2 id="fr-get" class="text-xl font-bold text-slate-900 sm:text-2xl">{{ $p['support_title'] }}</h2>
                    <ul class="mt-5 space-y-3">
                        @foreach ($p['support'] as $s)
                            <li class="flex gap-3 text-sm text-slate-700 sm:text-base"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg></span>{{ $s['text'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="flex flex-col gap-6">
                @if ($p['earn_text'])
                    <div class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
                        <h2 class="flex items-center gap-2 text-xl font-bold text-slate-900 sm:text-2xl"><x-icon name="banknotes" class="h-6 w-6 text-emerald-600" /> {{ $p['earn_title'] }}</h2>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600 sm:text-base">{{ $p['earn_text'] }}</p>
                    </div>
                @endif
                <div class="rounded-3xl bg-gradient-to-br from-blue-700 to-blue-900 p-6 text-white shadow-lg sm:p-8" data-franchise-invest>
                    <h2 class="flex items-center gap-2 text-xl font-bold sm:text-2xl"><x-icon name="chart-bar" class="h-6 w-6 text-amber-300" /> {{ $p['invest_title'] }}</h2>
                    @if ($p['invest'])
                        <dl class="mt-5 grid grid-cols-2 gap-3">
                            @foreach ($p['invest'] as $i)
                                <div class="rounded-2xl bg-white/10 p-4 ring-1 ring-inset ring-white/15"><dt class="text-xs text-blue-100">{{ $i['label'] }}</dt><dd class="mt-1 text-lg font-bold">{{ $i['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                    @if ($p['invest_note'])<p class="mt-4 text-sm leading-relaxed text-blue-100">{{ $p['invest_note'] }}</p>@endif
                </div>
            </div>
        </div>
    </section>

    {{-- ============ Who we look for ============ --}}
    @if ($p['ideal'])
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fr-ideal">
            <div class="grid gap-8 lg:grid-cols-[1fr_1.4fr] lg:items-center">
                <h2 id="fr-ideal" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $p['ideal_title'] }}</h2>
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($p['ideal'] as $i)
                        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700"><x-icon name="check-circle" class="h-5 w-5 shrink-0 text-blue-600" />{{ $i['text'] }}</li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ============ Steps ============ --}}
    @if ($p['steps'])
        <section class="bg-slate-950 text-white" aria-labelledby="fr-steps">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <h2 id="fr-steps" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $p['steps_title'] }}</h2>
                <ol class="mt-10 grid gap-6 sm:grid-cols-2 {{ [1 => "lg:grid-cols-1", 2 => "lg:grid-cols-2", 3 => "lg:grid-cols-3", 4 => "lg:grid-cols-4"][count($p["steps"])] ?? "lg:grid-cols-5" }}" data-franchise-steps>
                    @foreach ($p['steps'] as $n => $s)
                        <li class="relative">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-amber-400 text-base font-extrabold text-slate-900">{{ $n + 1 }}</span>
                            @if (! $loop->last)<span aria-hidden="true" class="absolute left-14 right-2 top-[22px] hidden h-px bg-white/20 lg:block"></span>@endif
                            <h3 class="mt-4 text-base font-semibold">{{ $s['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-300">{{ $s['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
                @if ($accepting)
                    <a href="{{ $applyUrl }}" class="mt-10 inline-flex min-h-12 items-center gap-2 rounded-xl bg-amber-400 px-6 text-base font-bold text-slate-900 hover:bg-amber-300">{{ $p['cta_label'] }}</a>
                @endif
            </div>
        </section>
    @endif

    {{-- ============ Cities ============ --}}
    @if ($p['cities'])
        <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fr-cities">
            <h2 id="fr-cities" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $p['cities_title'] }}</h2>
            <ul class="mt-6 flex flex-wrap gap-2">
                @foreach ($p['cities'] as $c)
                    <li class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-4 py-2 text-sm font-medium text-blue-800 ring-1 ring-inset ring-blue-100"><x-icon name="map-pin" class="h-4 w-4" />{{ $c['name'] }}</li>
                @endforeach
            </ul>
            @if ($p['cities_note'])<p class="mt-4 text-sm text-slate-600">{{ $p['cities_note'] }}</p>@endif
        </section>
    @endif

    {{-- ============ Partner stories (only what the Super Admin adds) ============ --}}
    @if ($p['stories'])
        <section class="bg-slate-50" aria-labelledby="fr-stories">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <h2 id="fr-stories" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $p['stories_title'] }}</h2>
                <ul class="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($p['stories'] as $s)
                        <li class="cf-card flex flex-col p-6">
                            <svg class="h-8 w-8 text-amber-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.5 6C6 7.5 4 10.5 4 14v4h6v-6H7c0-2.2 1.2-4 3.4-5L9.5 6zm10 0C16 7.5 14 10.5 14 14v4h6v-6h-3c0-2.2 1.2-4 3.4-5l-.9-1z"/></svg>
                            <blockquote class="mt-3 flex-1 text-sm leading-relaxed text-slate-700">{{ $s['quote'] }}</blockquote>
                            <p class="mt-4 text-sm font-semibold text-slate-900">{{ $s['name'] }} <span class="font-normal text-slate-500">· {{ $s['city'] }}</span></p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ============ FAQ ============ --}}
    @if ($p['faq'])
        <section class="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fr-faq">
            <h2 id="fr-faq" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $p['faq_title'] }}</h2>
            <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                @foreach ($p['faq'] as $f)
                    <details class="group py-4">
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 text-left text-base font-medium text-slate-900">
                            <span>{{ $f['q'] }}</span>
                            <span aria-hidden="true" class="shrink-0 text-slate-400 transition group-open:rotate-180"><x-icon name="chevron-down" class="h-4 w-4" /></span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $f['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============ Closing band ============ --}}
    <section class="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-blue-800 to-slate-900 px-6 py-10 text-white shadow-xl sm:px-12 sm:py-14">
            <div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-amber-300/20 blur-3xl"></div>
            <div class="relative grid gap-6 lg:grid-cols-[1.5fr_1fr] lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $p['final_title'] }}</h2>
                    @if ($p['final_text'])<p class="mt-3 text-base text-blue-100">{{ $p['final_text'] }}</p>@endif
                </div>
                <div class="flex flex-col gap-3 lg:items-end">
                    @if ($accepting)
                        <a href="{{ $applyUrl }}" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-amber-400 px-6 text-base font-bold text-slate-900 hover:bg-amber-300">{{ $p['cta_label'] }}</a>
                    @endif
                    @if ($tel || $wa || $mail)
                        <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-blue-100" data-franchise-contact>
                            @if ($tel)<a href="{{ $tel }}" class="inline-flex items-center gap-1.5 hover:text-white"><x-icon name="phone" class="h-4 w-4" />{{ C::get('phone') }}</a>@endif
                            @if ($wa)<a href="{{ $wa }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 hover:text-white"><x-icon name="chat" class="h-4 w-4" />WhatsApp</a>@endif
                            @if ($mail)<a href="mailto:{{ $mail }}" class="inline-flex items-center gap-1.5 hover:text-white"><x-icon name="document-text" class="h-4 w-4" />{{ $mail }}</a>@endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @if ($p['disclaimer'])<p class="mx-auto mt-6 max-w-3xl text-center text-xs leading-relaxed text-slate-500">{{ $p['disclaimer'] }}</p>@endif
    </section>
</div>
</x-layouts.customer>
