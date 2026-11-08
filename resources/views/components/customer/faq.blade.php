{{--
    "Questions" on a city page (owner 2026-11-08, SEO): three short questions people search for, answered in plain words,
    plus the same questions as FAQPage structured data. Words: Website text → "Questions on city pages" (faq.*), en + te.
    $section: services | parcel | food | shop. $city: the page's city (answers can name it with :city).
--}}
@props(['section', 'city'])
@php
    $t = fn (string $key) => \App\Support\SiteText::get($key, ['city' => $city->name]);
    $items = collect([1, 2, 3])
        ->map(fn ($n) => ['q' => $t("faq.{$section}.q{$n}"), 'a' => $t("faq.{$section}.a{$n}")])
        ->filter(fn ($i) => trim($i['q']) !== '' && trim($i['a']) !== '')
        ->values();
@endphp
@if ($items->isNotEmpty())
    <section class="mt-10" data-testid="faq" aria-labelledby="faq-title-{{ $section }}">
        <h2 id="faq-title-{{ $section }}" class="text-lg font-bold text-slate-900">{{ $t('faq.title') }}</h2>
        <div class="mt-3 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($items as $item)
                <details class="group px-4 py-3">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-slate-900">
                        {{ $item['q'] }}
                        <span class="text-slate-400 transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <p class="mt-2 text-sm text-slate-600">{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </section>
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $items->map(fn ($i) => [
            '@type' => 'Question', 'name' => $i['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']],
        ])->all(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
