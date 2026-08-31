@props([
    'banners',
    'id',
    'label' => 'Promotions',
    'variant' => 'hero',
    'autoplay' => true,
    // Auto-advance interval in milliseconds, set per call site (see
    // config/banners.php). Null = let carousel.js use its own 6000ms
    // default. The value is only ever passed through to a data attribute
    // here — no timing decision is made in this component.
    'interval' => null,
])

{{--
    The reusable, database-driven banner carousel (Phase C).

    Used TWICE on the homepage, from two independent `banners.placement`
    slots — `top` (the hero) and `mid` (the mid-page promotional strip) —
    and again on category pages for a `mid` banner targeted at that
    category. The two slots share this component but never share content:
    each is passed its own collection, resolved separately through
    Banner::scopeForSlot(). Nothing on either is authored here.

    ── No banner content is hard-coded ───────────────────────────────────────
    Every word, image and destination on a slide comes from the `banners`
    row. There is deliberately no invented CTA label: the schema has no
    `cta_text` column, so the slide's own title is the link text and an arrow
    supplies the affordance. Inventing "Shop now" here would be exactly the
    hard-coded banner content the architecture forbids. The columns that
    would let an admin control a subtitle, a separate mobile image, and a CTA
    label are recorded as a gap in the Phase C report.

    ── Fallback ──────────────────────────────────────────────────────────────
    An empty collection renders NOTHING — no empty frame, no placeholder
    slide, no grey box. The homepage's own discovery hero (heading, search,
    category shortcuts) stands on its own above this and is what a visitor
    sees when no banner is live.

    ── Accessibility (APG carousel pattern) ─────────────────────────────────
    - The region is `aria-roledescription="carousel"` with a real label, and
      each slide is a labelled `group` announcing its position.
    - Arrows, dots and a play/pause control are all real <button>s, reachable
      and operable by keyboard; Left/Right arrow keys work anywhere inside.
    - Auto-advance never starts under `prefers-reduced-motion`, pauses on
      hover/focus/hidden-tab, and can be stopped permanently by the viewer
      (WCAG 2.2.2). A polite live region announces the current slide only
      while rotation is stopped, so it never talks over the reader.
    - Slide images are decorative (`alt=""`): the title is rendered as real
      text next to them, and duplicating it in alt would say it twice.
    - Without JavaScript the track is still a scroll-snap row: swipeable on
      touch, scrollable by keyboard, with every slide reachable. The arrows
      and dots stay hidden until JS reveals them.

    ── Performance ───────────────────────────────────────────────────────────
    Only the first slide loads eagerly (it is the homepage's likely LCP
    element); every later slide is lazy. Explicit width/height on all of them
    reserves the box so nothing shifts as they arrive.
--}}

@php
    $banners = collect($banners);
@endphp

@if ($banners->isNotEmpty())
    @php
        $isHero = $variant === 'hero';
        $count = $banners->count();
        $multiple = $count > 1;
    @endphp

    <section data-carousel
             data-carousel-autoplay="{{ $autoplay && $multiple ? 'true' : 'false' }}"
             @if (filled($interval)) data-carousel-interval="{{ (int) $interval }}" @endif
             aria-roledescription="carousel"
             aria-label="{{ $label }}"
             {{ $attributes->merge(['class' => 'relative']) }}>

        {{-- Track. overflow-x-auto + snap = native swipe on touch and a
             working carousel with zero JavaScript.

             Deliberately NO `scroll-smooth` class here. Whether a scroll
             animates is decided in exactly one place — carousel.js passes
             `behavior` per call, honours prefers-reduced-motion, and falls
             back to an instant jump where smooth scrolling turns out to be
             unavailable. Declaring smoothness in the CSS as well would mean
             two sources of truth for the same decision, and the JS could no
             longer opt out of it. --}}
        <div data-carousel-track
             class="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($banners as $index => $banner)
                @php
                    $position = $index + 1;
                    $slideLabel = $position.' of '.$count.($banner->title ? ': '.$banner->title : '');
                @endphp
                <div data-carousel-slide
                     role="group"
                     aria-roledescription="slide"
                     aria-label="{{ $slideLabel }}"
                     class="w-full shrink-0 snap-start">
                    @php
                        $inner = $banner->image_url;

                        /*
                         | `banners.link` is admin-editable free text and this
                         | page is public and unauthenticated, so the scheme is
                         | validated before it reaches an href: one careless or
                         | compromised admin edit must not become a
                         | `javascript:` URL fired at every visitor. Same
                         | reasoning (and the same threat) as the
                         | `allow_unsafe_links` guard PageController already
                         | applies to admin-authored CMS markdown.
                         |
                         | Relative paths and http/https/mailto/tel pass; every
                         | other scheme resolves to null and the slide simply
                         | renders as a non-clickable panel.
                         */
                        $href = null;
                        if (filled($banner->link)) {
                            $scheme = parse_url($banner->link, PHP_URL_SCHEME);
                            if ($scheme === null || in_array(strtolower((string) $scheme), ['http', 'https', 'mailto', 'tel'], true)) {
                                $href = $banner->link;
                            }
                        }
                    @endphp

                    <div class="relative overflow-hidden rounded-2xl bg-slate-900">
                        @if ($inner)
                            {{-- One stored image serves both breakpoints —
                                 the schema has no separate mobile asset (see
                                 the gap note above). A taller box on small
                                 screens and object-cover keep the subject
                                 centred instead of letterboxed. --}}
                            <img src="{{ $inner }}"
                                 alt=""
                                 @if ($index === 0 && $isHero) fetchpriority="high" @else loading="lazy" @endif
                                 decoding="async"
                                 width="1200"
                                 height="{{ $isHero ? 420 : 260 }}"
                                 @class([
                                     'w-full object-cover',
                                     'h-40 sm:h-64 lg:h-[22rem]' => $isHero,
                                     'h-36 sm:h-44 lg:h-52' => ! $isHero,
                                 ])>
                        @else
                            {{-- A banner row with no usable image. Still a
                                 real, readable promo rather than a broken
                                 <img> icon. --}}
                            <div @class([
                                'w-full bg-gradient-to-br from-slate-800 to-slate-900',
                                'h-40 sm:h-64 lg:h-[22rem]' => $isHero,
                                'h-36 sm:h-44 lg:h-52' => ! $isHero,
                            ])></div>
                        @endif

                        @if ($banner->title)
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/75 via-black/35 to-transparent p-4 sm:p-6">
                                <p @class([
                                    'font-bold text-white drop-shadow',
                                    'text-lg sm:text-2xl lg:text-3xl' => $isHero,
                                    'text-base sm:text-lg' => ! $isHero,
                                ])>{{ $banner->title }}</p>

                                @if ($href)
                                    {{-- Affordance only. The real link is the
                                         stretched anchor below, so there is
                                         exactly one tab stop per slide and no
                                         invented CTA wording. --}}
                                    <span aria-hidden="true" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-white/90">
                                        View
                                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                            <path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                @endif
                            </div>
                        @endif

                        @if ($href)
                            {{-- Stretched link: covers the whole slide, keeps
                                 one code path for linked and unlinked banners,
                                 and takes its accessible name from the
                                 banner's own title — never from hard-coded
                                 copy. --}}
                            <a href="{{ $href }}"
                               class="absolute inset-0 rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                                <span class="sr-only">{{ $banner->title ?: 'Open promotion' }}</span>
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($multiple)
            {{-- Arrows. `hidden` in the markup, revealed by carousel.js, so a
                 no-JS viewer never sees a control that does nothing. --}}
            <button type="button" data-carousel-prev data-carousel-enhanced hidden
                    class="absolute left-2 top-1/2 hidden h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-slate-900 shadow transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:grid">
                <span class="sr-only">Previous slide</span>
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                    <path fill-rule="evenodd" d="M12.7 15.7a1 1 0 01-1.4 0l-5-5a1 1 0 010-1.4l5-5a1 1 0 111.4 1.4L8.4 10l4.3 4.3a1 1 0 010 1.4z" clip-rule="evenodd" />
                </svg>
            </button>
            <button type="button" data-carousel-next data-carousel-enhanced hidden
                    class="absolute right-2 top-1/2 hidden h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-slate-900 shadow transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:grid">
                <span class="sr-only">Next slide</span>
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                    <path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" />
                </svg>
            </button>

            <div class="mt-3 flex items-center justify-center gap-3">
                <div data-carousel-enhanced hidden class="flex items-center gap-2">
                    @foreach ($banners as $index => $banner)
                        {{-- min-h/min-w 44px hit area via padding, with the
                             visible dot inside — a 8px tap target fails
                             WCAG 2.1 AA 2.5.5. --}}
                        <button type="button" data-carousel-dot
                                aria-current="{{ $index === 0 ? 'true' : 'false' }}"
                                class="grid h-11 w-6 place-items-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            <span class="sr-only">Go to slide {{ $index + 1 }} of {{ $count }}</span>
                            <span data-carousel-pip aria-hidden="true"
                                  class="block h-2 w-2 rounded-full transition {{ $index === 0 ? 'bg-slate-900' : 'bg-slate-300' }}"></span>
                        </button>
                    @endforeach
                </div>

                @if ($autoplay)
                    <button type="button" data-carousel-toggle data-carousel-enhanced hidden
                            aria-pressed="false"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-md px-2 text-xs font-medium text-slate-500 transition hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                            <path d="M6 4h2.5v12H6V4zm5.5 0H14v12h-2.5V4z" />
                        </svg>
                        <span data-carousel-toggle-label class="sr-only">Pause banner rotation</span>
                    </button>
                @endif
            </div>

            {{-- Announces the current slide, but only while rotation is
                 stopped — see carousel.js. --}}
            <p data-carousel-status role="status" aria-live="polite" class="sr-only"></p>
        @endif
    </section>
@endif
