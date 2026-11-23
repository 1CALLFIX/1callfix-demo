{{--
    "Explore 1CallFix" doors (owner mockup 2026-10-30): one tile per business live for this visitor (Admin → Modules),
    in the Super Admin's order with their pictures (Admin → Growth → Homepage sections). Words from SiteText.
    Owner 2026-11-23 (the Flipkart pattern): small icons in ONE row everywhere; on phones the row swipes sideways (the
    next business peeks in, no scroll bar shows), on computers it is centred and only scrolls, with ‹ › arrows, once
    more businesses are added than fit. The live line ("46 services to book") is gone from view: the number sits as a
    badge on the picture, only from the Super Admin's "from" number up (HomeDoors::badge). On phones the heading and
    the line under it are skipped so the businesses sit right under the search bar.
--}}
@php $platform = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp
<section aria-labelledby="explore-heading" class="cf-doors-sec mx-auto max-w-7xl px-4 pt-6 pb-2 sm:px-6 lg:px-8" data-testid="home-doors">
    <style>
        @media (max-width:639.98px){
            .cf-doors-sec.cf-doors-sec{padding-top:.5rem;padding-bottom:.25rem}
            .cf-explore-title{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
            .cf-explore-line{display:none}
        }
    </style>
    <h2 id="explore-heading" class="cf-explore-title text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
        {{ \App\Support\SiteText::get('home.explore_title', ['brand' => $platform]) }}
    </h2>
    @if (\App\Support\HomeDoors::showPromise())
        <p class="cf-explore-line mt-1 text-sm text-slate-600">{{ \App\Support\SiteText::get('home.promise') }}</p>
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
            .cf-doorbar ul{display:grid;grid-template-columns:repeat(var(--cols-phone),minmax(0,1fr));gap:.3rem;max-width:80rem;margin:0 auto;padding:.4rem .5rem .55rem;list-style:none}
            .cf-doorbar a{display:flex;align-items:center;justify-content:center;min-height:40px;padding:0 .15rem;border-radius:9999px;
                font-size:.72rem;font-weight:800;color:var(--mod-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none;
                background:linear-gradient(180deg,#fff,var(--mod-soft));border:1.5px solid color-mix(in srgb,var(--mod) 35%,transparent);
                box-shadow:0 1px 2px rgba(15,23,42,.06);transition:background .15s,color .15s,transform .15s,box-shadow .15s}
            .cf-doorbar a:hover,.cf-doorbar a:focus-visible{background:var(--mod);color:#fff;border-color:var(--mod);transform:translateY(-1px);
                box-shadow:0 6px 14px -6px var(--mod);outline:none}
            @media (min-width:640px){.cf-doorbar ul{grid-template-columns:repeat(var(--cols),minmax(0,1fr));gap:.6rem;padding:.5rem 1.5rem .65rem}
                .cf-doorbar a{font-size:.9rem;min-height:42px;padding:0 .35rem;letter-spacing:.01em}}
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

    {{-- The doors: one row of small icons (owner 2026-11-23). Styled inline: no front-end build needed. --}}
    <style>
        .cf-doors-wrap{position:relative}
        .cf-doors{display:flex;gap:.5rem;overflow-x:auto;overscroll-behavior-x:contain;scroll-snap-type:x proximity;scroll-padding-left:1rem;
            scrollbar-width:none;list-style:none;margin:0 -1rem;padding:.55rem 1rem .35rem}
        .cf-doors::-webkit-scrollbar{display:none}
        {{-- position:relative keeps the screen-reader line (sr-only, absolutely placed) inside the swipe row; without it
             the hidden lines of the far-right doors widen the whole page and it wobbles sideways on phones. --}}
        .cf-doors>li{position:relative;flex:0 0 auto;min-width:3.9rem;scroll-snap-align:start}
        .cf-doors a{display:flex;flex-direction:column;align-items:center;text-align:center;text-decoration:none;border-radius:1rem;outline-offset:3px}
        .cf-door-pic{position:relative;display:grid;place-items:center;width:3.4rem;height:3.4rem;border-radius:1rem;background:var(--mod-soft);
            box-shadow:inset 0 0 0 1.5px color-mix(in srgb,var(--mod) 28%,transparent),0 4px 10px -6px var(--mod);transition:box-shadow .2s,transform .2s}
        .cf-door-pic img{width:100%;height:100%;border-radius:inherit;transition:transform .3s}
        .cf-doors a:hover .cf-door-pic{box-shadow:inset 0 0 0 2px var(--mod),0 8px 16px -8px var(--mod);transform:translateY(-2px)}
        .cf-doors a:hover .cf-door-pic img{transform:scale(1.05)}
        .cf-door-name{margin-top:.35rem;font-size:.74rem;font-weight:700;line-height:1.2;white-space:nowrap;color:#0f172a}
        .cf-door-count{position:absolute;top:-6px;right:-8px;min-width:1.15rem;height:1.15rem;padding:0 .28rem;border-radius:9999px;box-sizing:content-box;
            background:var(--mod);color:#fff;border:2px solid #fff;font-size:.64rem;font-weight:800;font-style:normal;line-height:1.15rem;text-align:center}
        .cf-doors-arrow{display:none}
        @media (min-width:640px){
            .cf-doors{gap:1.75rem;width:max-content;max-width:100%;margin:0 auto;padding:.75rem .5rem .5rem;scroll-padding-left:.5rem}
            .cf-doors>li{min-width:6rem}
            .cf-door-pic{width:5rem;height:5rem;border-radius:1.4rem}
            .cf-door-name{margin-top:.5rem;font-size:.95rem}
            .cf-door-count{top:-7px;right:-9px;min-width:1.4rem;height:1.4rem;line-height:1.4rem;font-size:.74rem}
            .cf-doors-arrow{position:absolute;top:1.9rem;z-index:2;width:2.75rem;height:2.75rem;align-items:center;justify-content:center;border-radius:9999px;
                background:#fff;border:1px solid #e2e8f0;box-shadow:0 4px 12px -4px rgba(15,23,42,.3);font-size:1.6rem;line-height:1;color:#0f172a}
            .cf-doors-arrow[data-on]{display:flex}
            .cf-doors-arrow.l{left:-.5rem}.cf-doors-arrow.r{right:-.5rem}
        }
        @media (prefers-reduced-motion:reduce){.cf-door-pic,.cf-door-pic img{transition:none}.cf-doors a:hover .cf-door-pic{transform:none}}
    </style>
    <div class="cf-doors-wrap"
         x-data="{ l: false, r: false,
             edge() { const u = this.$refs.row; this.l = u.scrollLeft > 4; this.r = u.scrollLeft + u.clientWidth < u.scrollWidth - 4; },
             go(d) { const u = this.$refs.row; u.scrollBy({ left: d * u.clientWidth * 0.8, behavior: 'smooth' }); } }"
         x-init="$nextTick(() => edge())" @resize.window.debounce.150ms="edge()">
        <button type="button" class="cf-doors-arrow l" :data-on="l ? '' : null" @click="go(-1)" aria-label="Scroll left" tabindex="-1">‹</button>
        <ul class="cf-doors" x-ref="row" @scroll.passive.debounce.60ms="edge()" data-testid="home-doors-grid">
            @foreach ($doors as $door)
                <li wire:key="door-{{ $door['key'] }}">
                    <a href="{{ $door['url'] }}" wire:navigate data-door="{{ $door['key'] }}" style="{{ \App\Support\ModuleTheme::style($door['key']) ?: \App\Support\ModuleTheme::style('services') }}"
                       class="group focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600" title="{{ $door['line'] }}">
                        <span class="cf-door-pic">
                            <img src="{{ $door['image'] }}" alt="" width="80" height="80" loading="eager" decoding="async"
                                 class="{{ \App\Support\SiteControls::imageFit() === 'cover' ? 'object-cover' : 'object-contain' }}">
                            @if (($door['badge'] ?? null) !== null)
                                <i class="cf-door-count" aria-hidden="true" data-door-badge="{{ $door['key'] }}">{{ $door['badge'] }}</i>
                            @endif
                        </span>
                        <span class="cf-door-name">{{ $door['title'] }}</span>
                        {{-- The live line stays for screen readers (and as the hover hint), not on screen. --}}
                        <span class="sr-only">{{ $door['line'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <button type="button" class="cf-doors-arrow r" :data-on="r ? '' : null" @click="go(1)" aria-label="Scroll right" tabindex="-1">›</button>
    </div>
</section>
