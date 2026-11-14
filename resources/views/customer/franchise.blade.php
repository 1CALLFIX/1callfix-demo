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
    // Business picker: shown only when the Super Admin set the all / two / add-on fees as plain numbers.
    $fees = ['all' => $p['fee_all'], 'two' => $p['fee_two'], 'addon' => $p['fee_addon'], 'one' => $p['fee_one']];
    $pickerOn = count($businesses) > 1 && ctype_digit((string) $fees['all']) && ctype_digit((string) $fees['two']) && ctype_digit((string) $fees['addon']);
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
                @elseif ($businesses)
                    {{-- No picture uploaded (owner 2026-11-14): every live business, as on the homepage "Explore" row, in two
                         rows scrolling past each other. Stops for people who turned motion off. --}}
                    <div class="fr-marquee relative overflow-hidden rounded-3xl bg-white/5 py-5 shadow-2xl ring-1 ring-inset ring-white/10 backdrop-blur-sm" aria-hidden="true" data-franchise-hero-tiles>
                        @foreach ([0, 1] as $row)
                            @php $rowBiz = $row ? array_reverse($businesses) : $businesses; @endphp
                            <div @class(['flex w-max gap-3 px-1.5', 'fr-marquee-left' => ! $row, 'fr-marquee-right mt-3' => $row])>
                                @foreach (array_merge($rowBiz, $rowBiz, count($rowBiz) < 5 ? $rowBiz : []) as $b)
                                    <div class="flex w-36 shrink-0 flex-col items-center rounded-2xl bg-white p-3 text-center text-slate-900 shadow-lg sm:w-40" style="{{ $b['style'] }}">
                                        <span class="grid aspect-square w-20 place-items-center overflow-hidden rounded-xl bg-[var(--mod-soft)] text-[var(--mod)]">
                                            @if ($b['image'])<img src="{{ $b['image'] }}" alt="" class="h-full w-full object-contain" loading="eager" decoding="async">@else<x-icon :name="$b['icon']" class="h-9 w-9" />@endif
                                        </span>
                                        <span class="mt-2 text-sm font-bold">{{ $b['title'] }}</span>
                                        <span class="mt-0.5 line-clamp-1 text-[11px] text-slate-500">{{ $b['line'] ?: $b['label'] }}</span>
                                        <span class="mt-2 block h-1 w-10 rounded-full bg-[var(--mod)]"></span>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                        <div class="pointer-events-none absolute inset-y-0 left-0 w-10 bg-gradient-to-r from-blue-950/80 to-transparent"></div>
                        <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-blue-900/80 to-transparent"></div>
                    </div>
                    <style>
                        @keyframes fr-left { from { transform: translateX(0) } to { transform: translateX(-50%) } }
                        @keyframes fr-right { from { transform: translateX(-50%) } to { transform: translateX(0) } }
                        .fr-marquee-left { animation: fr-left 38s linear infinite }
                        .fr-marquee-right { animation: fr-right 42s linear infinite }
                        .fr-marquee:hover .fr-marquee-left, .fr-marquee:hover .fr-marquee-right { animation-play-state: paused }
                        @media (prefers-reduced-motion: reduce) { .fr-marquee-left, .fr-marquee-right { animation: none } }
                    </style>
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
                @php
                    // Owner 2026-11-14: colourful, each tile its own colour.
                    $whyLook = [['bg-blue-50 ring-blue-100', 'from-blue-600 to-sky-500', 'text-blue-800'], ['bg-emerald-50 ring-emerald-100', 'from-emerald-600 to-teal-500', 'text-emerald-800'],
                        ['bg-amber-50 ring-amber-100', 'from-amber-500 to-orange-500', 'text-orange-800'], ['bg-fuchsia-50 ring-fuchsia-100', 'from-fuchsia-600 to-pink-500', 'text-fuchsia-800'],
                        ['bg-violet-50 ring-violet-100', 'from-violet-600 to-indigo-500', 'text-violet-800'], ['bg-rose-50 ring-rose-100', 'from-rose-600 to-red-500', 'text-rose-800']];
                @endphp
                @foreach ($p['why'] as $w)
                    @php [$card, $grad, $ink] = $whyLook[$loop->index % count($whyLook)]; @endphp
                    <li class="rounded-3xl p-6 ring-1 ring-inset {{ $card }} transition hover:-translate-y-0.5 hover:shadow-lg">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $grad }} text-white shadow-md"><x-icon :name="$w['icon']" class="h-6 w-6" /></span>
                        <h3 class="mt-4 text-lg font-bold {{ $ink }}">{{ $w['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $w['text'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ============ Every business, one franchise (live from the module switches) ============
         Owner 2026-11-14: each business drawn like its homepage "Explore" tile (same picture and colour), with a line on
         why it grows (Super Admin words, note_<module>). Cards keep one size; a short last row sits in the middle. --}}
    @if (count($businesses) > 1 && $p['biz_title'])
        <section class="bg-gradient-to-br from-blue-950 via-indigo-900 to-blue-800 text-white" aria-labelledby="fr-biz" data-franchise-businesses>
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="max-w-2xl">
                    <h2 id="fr-biz" class="text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $p['biz_title'] }}</h2>
                    @if ($p['biz_intro'])<p class="mt-3 text-base text-blue-100">{{ $p['biz_intro'] }}</p>@endif
                </div>
                <ul class="mt-8 flex flex-wrap justify-center gap-4">
                    @foreach ($businesses as $b)
                        <li class="w-full sm:w-[calc(50%-0.5rem)] lg:w-[calc(25%-0.75rem)]" data-franchise-business="{{ $b['slug'] }}">
                            <div class="relative flex h-full gap-4 overflow-hidden rounded-3xl bg-white p-4 text-slate-900 shadow-xl transition hover:-translate-y-0.5 sm:flex-col sm:p-5" style="{{ $b['style'] }}">
                                <span class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-2xl bg-[var(--mod-soft)] text-[var(--mod)] sm:h-24 sm:w-24">
                                    @if ($b['image'])<img src="{{ $b['image'] }}" alt="" class="h-full w-full object-contain" loading="lazy" decoding="async">@else<x-icon :name="$b['icon']" class="h-10 w-10" />@endif
                                </span>
                                <span class="min-w-0 pb-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-lg font-extrabold">{{ $b['title'] }}</span>
                                        @if ($p['biz_live'])<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>{{ $p['biz_live'] }}</span>@endif
                                    </span>
                                    @if ($b['line'])<span class="mt-0.5 block text-sm font-semibold text-[var(--mod-ink)]">{{ $b['line'] }}</span>@endif
                                    @if ($b['note'])<span class="mt-2 block text-sm leading-relaxed text-slate-600">{{ $b['note'] }}</span>@endif
                                </span>
                                <span class="absolute inset-x-0 bottom-0 h-1.5 bg-[var(--mod)]" aria-hidden="true"></span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ============ Comparison ============ --}}
    @if ($p['compare'])
        <section class="mx-auto max-w-5xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fr-compare" data-franchise-compare>
            <h2 id="fr-compare" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $p['compare_title'] }}</h2>
            <div class="mt-8 overflow-hidden rounded-3xl ring-1 ring-slate-200">
                <div class="grid grid-cols-[1fr_1fr] sm:grid-cols-[0.8fr_1fr_1.3fr]">
                    <div class="hidden bg-slate-50 p-4 sm:block"></div>
                    <div class="bg-slate-100 p-4 text-sm font-bold text-slate-600">{{ $p['compare_them'] }}</div>
                    <div class="bg-blue-600 p-4 text-sm font-bold text-white">{{ $p['compare_us'] }}</div>
                    @foreach ($p['compare'] as $row)
                        <div class="col-span-2 border-t border-slate-200 bg-slate-50 px-4 pt-3 text-xs font-bold uppercase tracking-wide text-slate-500 sm:col-span-1 sm:py-4 sm:text-sm sm:normal-case sm:tracking-normal sm:text-slate-900">{{ $row['label'] }}</div>
                        <div class="flex items-start gap-2 bg-white p-4 text-sm text-slate-500 sm:border-t sm:border-slate-200">
                            <span aria-hidden="true" class="mt-0.5 text-slate-400">–</span> {{ $row['them'] }}
                        </div>
                        <div class="flex items-start gap-2 bg-blue-50 p-4 text-sm font-semibold text-slate-900 sm:border-t sm:border-blue-100">
                            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg></span>
                            {{ $row['us'] }}
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ============ Plans, business picker, payment, areas (owner 2026-11-14: charge per business picked) ============ --}}
    @if ($p['plans'] || $pickerOn)
        <section class="bg-gradient-to-b from-amber-50 via-white to-white" aria-labelledby="fr-plans" data-franchise-plans>
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    @if ($p['plans_badge'])<p class="inline-flex items-center gap-1.5 rounded-full bg-rose-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-white"><x-icon name="sparkles" class="h-3.5 w-3.5" /> {{ $p['plans_badge'] }}</p>@endif
                    <h2 id="fr-plans" class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $p['plans_title'] }}</h2>
                    @if ($p['plans_intro'])<p class="mt-3 text-base text-slate-600">{{ $p['plans_intro'] }}</p>@endif
                </div>

                @if ($p['plans'])
                    @php $planLook = [['ring-amber-400 bg-gradient-to-br from-amber-400 to-orange-500', 'text-white', 'bg-white text-slate-900 hover:bg-amber-50'], ['ring-blue-200 bg-white', 'text-slate-900', 'bg-blue-600 text-white hover:bg-blue-700'], ['ring-emerald-200 bg-white', 'text-slate-900', 'bg-emerald-600 text-white hover:bg-emerald-700'], ['ring-violet-200 bg-white', 'text-slate-900', 'bg-violet-600 text-white hover:bg-violet-700']]; @endphp
                    <ul class="mx-auto mt-10 grid max-w-6xl gap-5 {{ [1 => 'max-w-md', 2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3'][count($p['plans'])] ?? 'md:grid-cols-2 lg:grid-cols-4' }}">
                        @foreach ($p['plans'] as $plan)
                            @php [$card, $ink, $btn] = $planLook[$loop->index % count($planLook)]; @endphp
                            <li class="relative flex flex-col rounded-3xl p-6 shadow-lg ring-2 {{ $card }} {{ $ink }}" data-franchise-plan>
                                <h3 class="text-lg font-extrabold">{{ $plan['name'] }}</h3>
                                @if ($plan['text'])<p @class(['mt-1 text-sm', 'text-amber-50' => $loop->first, 'text-slate-500' => ! $loop->first])>{{ $plan['text'] }}</p>@endif
                                <p class="mt-5 flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-4xl font-extrabold tracking-tight">{{ $plan['price'] }}</span>
                                    @if ($plan['was'])<span @class(['text-base line-through', 'text-amber-100' => $loop->first, 'text-slate-400' => ! $loop->first])>{{ $plan['was'] }}</span>@endif
                                </p>
                                @if ($plan['royalty'] || $plan['validity'])
                                    <dl class="mt-5 grid grid-cols-2 gap-2 text-sm">
                                        @if ($plan['royalty'])<div @class(['rounded-xl px-3 py-2', 'bg-white/20' => $loop->first, 'bg-slate-50' => ! $loop->first])><dt class="text-xs opacity-80">{{ $p['plans_royalty_label'] }}</dt><dd class="font-bold">{{ $plan['royalty'] }}</dd></div>@endif
                                        @if ($plan['validity'])<div @class(['rounded-xl px-3 py-2', 'bg-white/20' => $loop->first, 'bg-slate-50' => ! $loop->first])><dt class="text-xs opacity-80">{{ $p['plans_term_label'] }}</dt><dd class="font-bold">{{ $plan['validity'] }}</dd></div>@endif
                                    </dl>
                                @endif
                                @if ($accepting)
                                    <div class="mt-auto pt-6"><a href="{{ $applyUrl }}" class="inline-flex min-h-12 w-full items-center justify-center rounded-xl px-5 text-base font-bold shadow-sm transition {{ $btn }}">{{ $p['cta_label'] }}</a></div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($p['plans_note'])<p class="mx-auto mt-5 max-w-3xl text-center text-xs leading-relaxed text-slate-500">{{ $p['plans_note'] }}</p>@endif
                @endif

                {{-- Pick only the businesses you want: the fee follows the Super Admin's all / two / add-on / one fees. --}}
                @if ($pickerOn)
                    <div class="mx-auto mt-14 max-w-5xl rounded-3xl bg-slate-900 p-6 text-white shadow-2xl sm:p-10" data-franchise-picker
                         x-data="{ picked: [], total: {{ count($businesses) }}, all: {{ (int) $fees['all'] }}, two: {{ (int) $fees['two'] }}, addon: {{ (int) $fees['addon'] }}, one: {{ ctype_digit((string) $fees['one']) ? (int) $fees['one'] : 0 }},
                                  toggle(s) { this.picked = this.picked.includes(s) ? this.picked.filter(x => x !== s) : [...this.picked, s]; },
                                  get fee() { const k = this.picked.length; if (! k) return null; if (k >= this.total) return this.all;
                                              if (k === 1) return this.one || null; return Math.min(this.two + (k - 2) * this.addon, this.all); },
                                  money(n) { return n >= 100000 ? '₹' + (Math.round(n / 10000) / 10).toString() + (n === 100000 ? ' Lakh' : ' Lakhs') : '₹' + n.toLocaleString('en-IN'); } }">
                        <h3 class="text-2xl font-extrabold tracking-tight">{{ $p['pick_title'] }}</h3>
                        @if ($p['pick_intro'])<p class="mt-2 text-sm text-slate-300">{{ $p['pick_intro'] }}</p>@endif
                        <div class="mt-6 grid gap-6 lg:grid-cols-[1.6fr_1fr] lg:items-center">
                            <ul class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                                @foreach ($businesses as $b)
                                    <li>
                                        <button type="button" @click="toggle(@js($b['slug']))" :aria-pressed="picked.includes(@js($b['slug'])).toString()" style="{{ $b['style'] }}"
                                                :class="picked.includes(@js($b['slug'])) ? 'bg-white text-slate-900 ring-[var(--mod)] ring-4' : 'bg-white/10 text-white ring-white/15 hover:bg-white/15'"
                                                class="flex min-h-14 w-full items-center gap-2.5 rounded-2xl p-2 text-left text-sm font-bold ring-1 ring-inset transition" data-pick="{{ $b['slug'] }}">
                                            <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-white text-[var(--mod)]">
                                                @if ($b['image'])<img src="{{ $b['image'] }}" alt="" class="h-full w-full object-contain" loading="lazy">@else<x-icon :name="$b['icon']" class="h-6 w-6" />@endif
                                            </span>
                                            <span class="min-w-0 flex-1 truncate">{{ $b['title'] }}</span>
                                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full" :class="picked.includes(@js($b['slug'])) ? 'bg-emerald-500 text-white' : 'ring-2 ring-white/30'">
                                                <svg x-show="picked.includes(@js($b['slug']))" style="display:none" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="rounded-2xl bg-white p-6 text-center text-slate-900" aria-live="polite">
                                <p class="text-sm font-semibold text-slate-500">{{ $p['pick_result'] }}</p>
                                <p class="mt-2 text-4xl font-extrabold tracking-tight text-blue-700" data-pick-fee>
                                    <span x-show="fee" x-text="fee ? money(fee) : ''"></span>
                                    <span x-show="! fee" class="text-2xl text-slate-300">—</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-500" x-show="picked.length" x-text="picked.length + ' / ' + total"></p>
                                <p class="mt-2 text-sm text-slate-600" x-show="picked.length === 1 && ! one" style="display:none">{{ $p['pick_one_note'] }}</p>
                                @if ($accepting)
                                    <a href="{{ $applyUrl }}" class="mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-amber-400 px-5 text-base font-bold text-slate-900 hover:bg-amber-300">{{ $p['cta_label'] }}</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                @if ($p['payment'] || $p['areas'])
                    <div class="mx-auto mt-14 grid max-w-6xl gap-6 lg:grid-cols-2">
                        @if ($p['payment'])
                            <div class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8" data-franchise-payment>
                                <h3 class="flex items-center gap-2 text-xl font-bold text-slate-900"><x-icon name="wallet" class="h-6 w-6 text-emerald-600" /> {{ $p['pay_title'] }}</h3>
                                <ol class="mt-5 space-y-4">
                                    @foreach ($p['payment'] as $pay)
                                        <li class="flex gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br {{ ['from-emerald-500 to-teal-500', 'from-blue-600 to-sky-500', 'from-amber-500 to-orange-500', 'from-fuchsia-600 to-pink-500'][$loop->index % 4] }} text-sm font-extrabold text-white">{{ $loop->iteration }}</span>
                                            <span><span class="block font-bold text-slate-900">{{ $pay['title'] }}</span><span class="text-sm text-slate-600">{{ $pay['text'] }}</span></span>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                        @if ($p['areas'])
                            <div class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8" data-franchise-areas>
                                <h3 class="flex items-center gap-2 text-xl font-bold text-slate-900"><x-icon name="map-pin" class="h-6 w-6 text-blue-600" /> {{ $p['areas_title'] }}</h3>
                                <ul class="mt-5 space-y-3">
                                    @foreach ($p['areas'] as $area)
                                        <li class="grid grid-cols-[auto_1fr] items-center gap-3 rounded-2xl {{ ['bg-blue-50', 'bg-violet-50', 'bg-emerald-50', 'bg-amber-50'][$loop->index % 4] }} p-4">
                                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-white text-blue-700 shadow-sm"><x-icon name="building" class="h-6 w-6" /></span>
                                            <span>
                                                <span class="block font-bold text-slate-900">{{ $area['type'] }}</span>
                                                <span class="text-sm text-slate-600">{{ $area['population'] }} · <span class="font-semibold text-slate-900">{{ $area['radius'] }}</span></span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
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

    {{-- ============ Earnings calculator (shown only when the Super Admin set the share %) ============ --}}
    @if (is_numeric($p['calc_share']) && (float) $p['calc_share'] > 0)
        <section class="mx-auto max-w-5xl px-4 pt-16 sm:px-6 lg:px-8" aria-labelledby="fr-calc" data-franchise-calc>
            <div class="rounded-3xl bg-gradient-to-br from-emerald-50 via-white to-amber-50 p-6 ring-1 ring-emerald-100 sm:p-10"
                 x-data="{ orders: 50, value: 600, share: {{ (float) $p['calc_share'] }},
                          get monthly() { return Math.round(this.orders * 30 * this.value * this.share / 100); },
                          money(n) { return '₹' + n.toLocaleString('en-IN'); } }">
                <h2 id="fr-calc" class="flex items-center gap-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl"><x-icon name="chart-bar" class="h-7 w-7 text-emerald-600" /> {{ $p['calc_title'] }}</h2>
                <div class="mt-8 grid gap-8 lg:grid-cols-2 lg:items-center">
                    <div class="space-y-6">
                        <label class="block">
                            <span class="flex items-baseline justify-between text-sm font-semibold text-slate-700">{{ $p['calc_orders'] }} <span class="text-lg font-bold text-slate-900" x-text="orders"></span></span>
                            <input type="range" min="10" max="500" step="10" x-model.number="orders" class="mt-2 h-11 w-full accent-emerald-600">
                        </label>
                        <label class="block">
                            <span class="flex items-baseline justify-between text-sm font-semibold text-slate-700">{{ $p['calc_value'] }} <span class="text-lg font-bold text-slate-900" x-text="money(value)"></span></span>
                            <input type="range" min="100" max="3000" step="50" x-model.number="value" class="mt-2 h-11 w-full accent-emerald-600">
                        </label>
                        <p class="text-sm text-slate-600">{{ $p['calc_share_label'] }}: <span class="font-bold text-slate-900">{{ rtrim(rtrim(number_format((float) $p['calc_share'], 2), '0'), '.') }}%</span></p>
                    </div>
                    <div class="rounded-2xl bg-slate-900 p-6 text-center text-white shadow-xl">
                        <p class="text-sm text-slate-300">{{ $p['calc_result'] }}</p>
                        <p class="mt-2 text-4xl font-extrabold tracking-tight text-amber-300 sm:text-5xl" x-text="money(monthly)" data-calc-result></p>
                        <p class="mt-2 text-xs text-slate-400"><span x-text="orders"></span> orders × 30 days × <span x-text="money(value)"></span></p>
                        <a href="{{ $applyUrl }}" class="mt-5 inline-flex min-h-12 items-center rounded-xl bg-amber-400 px-6 text-base font-bold text-slate-900 hover:bg-amber-300">{{ $p['cta_label'] }}</a>
                    </div>
                </div>
                @if ($p['calc_note'])<p class="mt-6 text-xs leading-relaxed text-slate-500">{{ $p['calc_note'] }}</p>@endif
            </div>
        </section>
    @endif

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

    {{-- Phones (ease-of-use rule): Apply and WhatsApp / call stay at the bottom, above the menu bar. --}}
    <div class="h-20 lg:hidden"></div>
    <div class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-40 px-3 lg:hidden" data-franchise-bar>
        <div class="mx-auto flex max-w-md gap-2 rounded-2xl bg-slate-900/95 p-2 shadow-xl backdrop-blur">
            <a href="{{ $applyUrl }}" class="inline-flex min-h-12 flex-1 items-center justify-center rounded-xl bg-amber-400 px-4 text-base font-bold text-slate-900">{{ $p['cta_label'] }}</a>
            @if ($talkUrl)
                <a href="{{ $talkUrl }}" @if ($wa) target="_blank" rel="noopener" @endif aria-label="{{ $p['call_label'] ?: 'Talk to us' }}"
                   class="inline-flex min-h-12 w-12 items-center justify-center rounded-xl {{ $wa ? 'bg-emerald-500' : 'bg-blue-600' }} text-white"><x-icon :name="$wa ? 'chat' : 'phone'" class="h-6 w-6" /></a>
            @endif
        </div>
    </div>
</div>
</x-layouts.customer>
