{{--
    "Explore 1CallFix" doors (owner mockup 2026-10-30): one tile per business live for this visitor (Admin → Modules),
    in the Super Admin's order with their pictures (Admin → Growth → Homepage sections). Words from SiteText.
    Four fit a phone side by side (a fifth peeks in); more scroll sideways.
--}}
@php $platform = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp
<section aria-labelledby="explore-heading" class="mx-auto max-w-7xl px-4 pt-6 pb-2 sm:px-6 lg:px-8" data-testid="home-doors">
    <h2 id="explore-heading" class="text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
        {{ \App\Support\SiteText::get('home.explore_title', ['brand' => $platform]) }}
    </h2>
    @if (\App\Support\HomeDoors::showPromise())
        <p class="mt-1 text-sm text-slate-600">{{ \App\Support\SiteText::get('home.promise') }}</p>
    @endif

    {{-- Owner 2026-11-22 (Flipkart pattern): once the doors scroll up under the top bar, the same businesses stay in a
         slim bar right under it: names only (owner: "just show the modules names only … fit the modules without any
         slider bar … add some beauty"), each a pill in its business colour, all of them fitting with no scrolling
         (one row on computers, rows of 4 on phones), over a thin line in all the business colours. It shows as soon as
         the door pictures go under the top bar. Admin → Homepage sections → doors → 📌. Styled inline: no build. --}}
    @if (\App\Support\HomeDoors::sticky() && count($doors) > 1)
        @php
            $barCount = count($doors);
            $barColours = collect($doors)->map(fn ($d) => \App\Support\ModuleTheme::colour(isset(\App\Support\ModuleTheme::DEFAULTS[$d['key']]) ? $d['key'] : 'services'))->values();
            $barLine = $barColours->count() > 1 ? 'linear-gradient(90deg,'.$barColours->implode(',').')' : $barColours->first();
        @endphp
        <style>
            .cf-doorbar{position:fixed;left:0;right:0;z-index:39;background:rgba(255,255,255,.97);
                box-shadow:0 6px 16px -8px rgba(15,23,42,.25);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
            .cf-doorbar::after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:var(--line)}
            .cf-doorbar ul{display:grid;grid-template-columns:repeat(var(--cols-phone),minmax(0,1fr));gap:.375rem;max-width:80rem;margin:0 auto;padding:.45rem .75rem .6rem;list-style:none}
            .cf-doorbar a{display:flex;align-items:center;justify-content:center;min-height:40px;padding:0 .35rem;border-radius:9999px;
                font-size:.78rem;font-weight:800;letter-spacing:.01em;color:var(--mod-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none;
                background:linear-gradient(180deg,#fff,var(--mod-soft));border:1.5px solid color-mix(in srgb,var(--mod) 35%,transparent);
                box-shadow:0 1px 2px rgba(15,23,42,.06);transition:background .15s,color .15s,transform .15s,box-shadow .15s}
            .cf-doorbar a:hover,.cf-doorbar a:focus-visible{background:var(--mod);color:#fff;border-color:var(--mod);transform:translateY(-1px);
                box-shadow:0 6px 14px -6px var(--mod);outline:none}
            @media (min-width:640px){.cf-doorbar ul{grid-template-columns:repeat(var(--cols),minmax(0,1fr));gap:.6rem;padding:.5rem 1.5rem .65rem}
                .cf-doorbar a{font-size:.9rem;min-height:42px}}
            @media (prefers-reduced-motion:reduce){.cf-doorbar a{transition:none}.cf-doorbar a:hover{transform:none}}
        </style>
        <nav class="cf-doorbar" aria-label="{{ \App\Support\SiteText::get('home.explore_title', ['brand' => $platform]) }}" data-testid="doors-bar"
             x-data="{ on: false, top: 0,
                 check() { const h = document.querySelector('header'); this.top = h ? h.getBoundingClientRect().bottom : 0;
                           const g = document.querySelector('[data-testid=home-doors-grid]'); if (! g) { this.on = false; return; } const r = g.getBoundingClientRect();
                           this.on = r.top + r.height * 0.55 < this.top; } }"
             x-init="check()" @scroll.window.throttle.80ms="check()" @resize.window.throttle.200ms="check()"
             x-show="on" x-transition.opacity.duration.150ms :style="{ top: top + 'px' }"
             style="display:none;--line:{{ $barLine }};--cols:{{ $barCount }};--cols-phone:{{ $barCount <= 4 ? $barCount : (int) ceil($barCount / 2) }}">
            <ul>
                @foreach ($doors as $door)
                    <li wire:key="doorbar-{{ $door['key'] }}">
                        <a href="{{ $door['url'] }}" wire:navigate data-door-bar="{{ $door['key'] }}" title="{{ $door['title'] }}" style="{{ \App\Support\ModuleTheme::style($door['key']) ?: \App\Support\ModuleTheme::style('services') }}">{{ $door['title'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    {{-- Owner 2026-11-22 ("remove the scrolling bar and adjust the modules to fit in the screen"; replaces the 2026-11-05
         fifth-peeks-in row): every door fits, no sideways scrolling. Computers: one row of all doors. Phones: up to 4
         in a row (5–6 doors: rows of 3; 7–8: rows of 4; more: rows of 4). Styled inline: no front-end build needed. --}}
    @php
        $doorCount = count($doors);
        $doorColsPhone = $doorCount <= 4 ? max(1, $doorCount) : ($doorCount <= 6 ? 3 : 4);
    @endphp
    <style>
        .cf-doors{grid-template-columns:repeat(var(--door-cols-phone),minmax(0,1fr));list-style:none;padding-left:0}
        @media (min-width:640px){.cf-doors{grid-template-columns:repeat(var(--door-cols),minmax(0,1fr))}}
        .cf-doors .cf-door-name{overflow-wrap:anywhere;line-height:1.2}
        @media (min-width:640px){.cf-doors a{padding:.75rem .5rem}.cf-doors .cf-door-name{font-size:.95rem}}
        @media (min-width:1280px){.cf-doors a{padding:1rem .75rem}.cf-doors .cf-door-name{font-size:1rem}}
    </style>
    <ul class="cf-doors mt-3 grid gap-2 pb-2 sm:gap-4" style="--door-cols:{{ max(1, $doorCount) }};--door-cols-phone:{{ $doorColsPhone }}" data-testid="home-doors-grid">
        @foreach ($doors as $door)
            <li wire:key="door-{{ $door['key'] }}">
                <a href="{{ $door['url'] }}" wire:navigate data-door="{{ $door['key'] }}" style="{{ \App\Support\ModuleTheme::style($door['key']) ?: \App\Support\ModuleTheme::style('services') }}"
                   class="group relative flex h-full flex-col items-center overflow-hidden rounded-2xl border border-slate-200 bg-white p-1.5 text-center shadow-sm shadow-slate-900/5 transition duration-200 hover:-translate-y-0.5 hover:border-[var(--mod)] hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:rounded-3xl sm:p-4">
                    {{-- Each business in its own colour (2026-11-07): tinted picture box and a bar along the bottom. --}}
                    <span class="grid aspect-square w-full max-w-16 place-items-center overflow-hidden rounded-xl bg-[var(--mod-soft)] sm:max-w-28 sm:rounded-2xl">
                        <img src="{{ $door['image'] }}" alt="" width="112" height="112" loading="eager" decoding="async"
                             @class(["h-full w-full transition duration-300 group-hover:scale-105", \App\Support\SiteControls::imageFit() === "cover" ? "object-cover" : "object-contain"])>
                    </span>
                    <span class="cf-door-name mt-1.5 text-xs font-bold text-slate-900 sm:mt-2 sm:text-base">{{ $door['title'] }}</span>
                    <span class="mt-0.5 line-clamp-2 text-[10px] leading-tight text-slate-500 sm:text-xs">{{ $door['line'] }}</span>
                    <span class="absolute inset-x-0 bottom-0 h-1 bg-[var(--mod)]" aria-hidden="true"></span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
