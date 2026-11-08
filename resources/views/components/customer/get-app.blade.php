{{--
    "Get the app" (owner 2026-11-08: "design it carefully, heavy competition ahead"). One section used on the homepage
    (Homepage sections → "Get the app") and as the /app page. Android: the Play Store button. Computers: a QR code
    the phone camera opens (/app?go=1 sends Android straight to the store). iPhone: "Add to Home Screen" steps (no iOS
    app). Never drawn inside our Android apps. Every word: Website text → "Get the app section", en + te.
    $page: true on /app (the heading is the page's h1).
--}}
@props(['page' => false])
@unless (\App\Support\NativeApp::inApp())
@php
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r + ['brand' => \App\Services\Seo\SeoSettings::siteName()]);
    $modules = [
        'services' => $t('get_app.services'),
        'food' => $t('get_app.food'),
        'parcel' => $t('get_app.parcel'),
        'shop' => $t('get_app.shop'),
    ];
    $points = [
        ['map-pin', $t('get_app.point1')],
        ['bell', $t('get_app.point2')],
        ['shield', $t('get_app.point3')],
        ['check-circle', $t('get_app.point4')],
    ];
    $ios = \App\Support\AppLinks::ios();
    $heading = $page ? 'h1' : 'h2';
@endphp
<section data-testid="get-app" aria-labelledby="get-app-title" @class(['px-4 sm:px-6 lg:px-8', 'py-12' => ! $page, 'py-8 sm:py-12' => $page])>
    <div class="relative mx-auto max-w-7xl overflow-hidden rounded-[2rem] bg-gradient-to-br from-blue-700 via-blue-600 to-indigo-700 text-white shadow-2xl shadow-blue-900/20">
        {{-- Soft light behind the content. --}}
        <div aria-hidden="true" class="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full bg-sky-300/30 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -bottom-32 right-0 h-96 w-96 rounded-full bg-indigo-400/30 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:radial-gradient(white_1px,transparent_1px)] [background-size:22px_22px]"></div>

        <div class="relative grid items-center gap-10 px-6 py-10 sm:px-10 sm:py-14 lg:grid-cols-[1.15fr_0.85fr] lg:gap-6 lg:px-14">
            {{-- Words and buttons --}}
            <div>
                <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold tracking-wide ring-1 ring-white/25 backdrop-blur">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                    {{ $t('get_app.eyebrow') }}
                </p>
                <{{ $heading }} id="get-app-title" class="mt-4 text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl lg:text-[2.75rem]">
                    {{ $t('get_app.title') }}
                </{{ $heading }}>
                <p class="mt-4 max-w-xl text-base leading-relaxed text-blue-50/90 sm:text-lg">{{ $t('get_app.subtitle') }}</p>

                <ul class="mt-6 flex flex-wrap gap-2" aria-label="{{ $t('get_app.title') }}">
                    @foreach ($modules as $door => $label)
                        <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-1 pr-3 text-sm font-semibold text-slate-800 shadow-sm">
                            <img src="{{ \App\Support\HomeDoors::imageUrl($door) }}" alt="" width="28" height="28" class="h-7 w-7 rounded-full bg-blue-50 p-0.5">
                            {{ $label }}
                        </li>
                    @endforeach
                </ul>

                <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                    @foreach ($points as [$icon, $text])
                        <li class="flex items-start gap-3 text-sm text-blue-50">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white/15 ring-1 ring-white/20">
                                <x-icon :name="$icon" class="h-4 w-4" />
                            </span>
                            <span class="pt-1.5 leading-snug">{{ $text }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <x-customer.play-badge />
                    @if ($ios)
                        <a href="{{ $ios }}" target="_blank" rel="noopener" class="inline-flex h-14 items-center rounded-xl bg-white/10 px-4 text-sm font-semibold ring-1 ring-white/30 hover:bg-white/20">{{ $t('get_app.ios_button') }}</a>
                    @endif

                    {{-- Computers: point the phone camera here. --}}
                    <div class="hidden items-center gap-3 rounded-2xl bg-white p-2 pr-4 text-slate-800 shadow-lg md:flex">
                        <img src="{{ asset('images/app-qr.svg') }}" alt="{{ $t('get_app.qr_alt') }}" width="80" height="80" class="h-20 w-20 rounded-lg bg-white p-1">
                        <p class="max-w-[9rem] text-xs font-semibold leading-snug">{{ $t('get_app.qr') }}</p>
                    </div>
                </div>

                @unless ($ios)
                    <details class="group mt-5 max-w-xl rounded-xl bg-white/10 px-4 py-3 text-sm ring-1 ring-white/20">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold">
                            {{ $t('get_app.iphone') }}
                            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 transition group-open:rotate-180" />
                        </summary>
                        <p class="mt-2 leading-relaxed text-blue-50/90">{{ $t('get_app.iphone_steps') }}</p>
                    </details>
                @endunless
            </div>

            {{-- Phone picture (drawn, not a screenshot, so it never goes stale). --}}
            <div aria-hidden="true" class="relative mx-auto hidden w-full max-w-[19rem] sm:block">
                <div class="relative mx-auto w-64 rotate-[4deg] rounded-[2.6rem] bg-slate-950 p-2.5 shadow-2xl shadow-indigo-950/50 ring-1 ring-white/10 transition duration-500 hover:rotate-0">
                    <div class="absolute left-1/2 top-3.5 z-10 h-5 w-20 -translate-x-1/2 rounded-full bg-slate-950"></div>
                    <div class="overflow-hidden rounded-[2.1rem] bg-slate-50">
                        <div class="bg-gradient-to-br from-blue-600 to-indigo-600 px-4 pb-4 pt-9 text-white">
                            <p class="text-[11px] font-medium text-blue-100">{{ $t('get_app.mock_hello') }}</p>
                            <p class="mt-0.5 text-sm font-bold">{{ $t('get_app.mock_question') }}</p>
                            <div class="mt-3 flex items-center gap-2 rounded-full bg-white px-3 py-2 text-[11px] text-slate-400">
                                <x-icon name="magnifying-glass" class="h-3.5 w-3.5" />
                                {{ $t('get_app.mock_search') }}
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 p-3">
                            @foreach ($modules as $door => $label)
                                <div class="rounded-2xl bg-white p-2.5 shadow-sm ring-1 ring-slate-100">
                                    <img src="{{ \App\Support\HomeDoors::imageUrl($door) }}" alt="" width="40" height="40" class="h-10 w-10">
                                    <p class="mt-1.5 truncate text-[11px] font-semibold text-slate-800">{{ $label }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mx-3 mb-14 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-100">
                            <div class="flex items-center gap-2">
                                <span class="relative flex h-2.5 w-2.5">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75 motion-reduce:animate-none"></span>
                                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                                </span>
                                <p class="text-[11px] font-semibold text-slate-800">{{ $t('get_app.mock_tracking') }}</p>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full w-2/3 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500"></div>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- A notification arriving --}}
                <div class="absolute -bottom-6 -left-4 flex w-52 -rotate-2 items-center lg:-left-12 gap-2.5 rounded-2xl bg-white/95 p-2.5 text-slate-800 shadow-xl ring-1 ring-black/5 backdrop-blur lg:-left-10">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-blue-600 text-white"><x-icon name="bell" class="h-4 w-4" /></span>
                    <span class="min-w-0">
                        <span class="block truncate text-[11px] font-bold">{{ \App\Services\Seo\SeoSettings::siteName() }}</span>
                        <span class="block truncate text-[11px] text-slate-600">{{ $t('get_app.mock_notice') }}</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>
@endunless
