{{--
    Business cards, the Amazon way (owner 2026-11-14: "follow the amazon style, they haven't wasted a single inch of
    space"). Every card is the same size: the heading is the one link to everything (no second "View all" / "More"),
    a short line under it, then a 2 x 2 photo grid. Fewer than four things fill the same square (1 = one big photo,
    2 = two tall, 3 = one wide + two). Two cards across on a phone, three on a tablet. On a computer the row length
    follows the number of cards so no row ends half empty (6 or 9 cards = 3 across, 5 or 10 = 5 across, otherwise 4);
    a short last row that cannot be avoided sits in the middle.
    Data, order and words: Home::moduleCards() + HomeCards + SiteText (all Super Admin).
--}}
@php
    // Shapes so 1, 2, 3 or 4 tiles always fill the same square.
    $shapes = [
        1 => ['col-span-2 aspect-square'],
        2 => ['aspect-[1/2]', 'aspect-[1/2]'],
        3 => ['col-span-2 aspect-[2/1]', 'aspect-square', 'aspect-square'],
        4 => ['aspect-square', 'aspect-square', 'aspect-square', 'aspect-square'],
    ];
    // Each card its own colour, so the row is bright and easy to tell apart.
    $tints = ['from-blue-50 to-sky-100', 'from-amber-50 to-orange-100', 'from-emerald-50 to-teal-100', 'from-fuchsia-50 to-pink-100', 'from-violet-50 to-indigo-100', 'from-lime-50 to-green-100'];
    $accents = ['text-blue-700', 'text-orange-700', 'text-emerald-700', 'text-pink-700', 'text-indigo-700', 'text-green-700'];
    // Row length on a computer (owner 2026-11-14: no empty slots). Gaps: 0.625rem on a phone, 1rem from sm up.
    $total = count($cards);
    $across = match (true) { $total % 4 === 0 => 4, $total % 3 === 0 => 3, $total % 5 === 0 => 5, default => 4 };
    $wide = [3 => 'lg:w-[calc((100%-2rem)/3)]', 4 => 'lg:w-[calc((100%-3rem)/4)]', 5 => 'lg:w-[calc((100%-4rem)/5)]'][$across];
@endphp
<section aria-label="{{ \App\Support\SiteText::get('home.categories.sr') }}" class="mx-auto max-w-7xl px-3 py-4 sm:px-6 lg:px-8" data-testid="home-cards">
    <div class="flex flex-wrap justify-center gap-2.5 sm:gap-4" data-cards-across="{{ $across }}">
        @foreach ($cards as $i => $card)
            @php
                $tiles = array_slice($card['tiles'], 0, 4);
                $shape = $shapes[count($tiles)] ?? $shapes[4];
                $tint = $tints[$i % count($tints)];
                $accent = $accents[$i % count($accents)];
            @endphp
            <article class="flex w-[calc((100%-0.625rem)/2)] min-w-0 flex-col rounded-2xl sm:w-[calc((100%-1rem)/2)] md:w-[calc((100%-2rem)/3)] {{ $wide }} border border-slate-200 bg-white p-2.5 shadow-sm shadow-slate-900/5 transition hover:shadow-md sm:p-4"
                     aria-labelledby="card-{{ $card['key'] }}" data-card="{{ $card['key'] }}" wire:key="card-{{ $card['key'] }}">
                <a href="{{ $card['url'] }}" wire:navigate
                   class="group flex min-h-11 items-start justify-between gap-2 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    <h2 id="card-{{ $card['key'] }}" class="line-clamp-2 text-sm font-bold leading-snug tracking-tight text-slate-900 sm:text-base">{{ $card['title'] }}</h2>
                    <span class="sr-only">{{ \App\Support\SiteText::get('home.cards.view_all') }}</span>
                    <x-icon name="chevron-right" class="mt-0.5 h-5 w-5 shrink-0 {{ $accent }} transition group-hover:translate-x-0.5" />
                </a>
                @if ($card['line'])
                    <a href="{{ $card['line_url'] }}" wire:navigate data-testid="card-line"
                       class="-mt-1 mb-2 line-clamp-1 text-[11px] font-semibold {{ $accent }} hover:underline sm:text-xs">{{ $card['line'] }}</a>
                @else
                    <span class="mb-1"></span>
                @endif

                <ul class="mt-auto grid grid-cols-2 gap-1.5 sm:gap-2" @if (! empty($card['wide'])) data-testid="event-countdowns" @endif>
                    @foreach ($tiles as $n => $tile)
                        <li class="{{ $shape[$n] }} min-w-0">
                            <a href="{{ $tile['url'] }}" wire:navigate
                               class="group relative block h-full w-full overflow-hidden rounded-xl bg-gradient-to-br {{ $tint }} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                               @if (! empty($tile['tint'])) style="background: {{ $tile['tint'] }}" @endif>
                                @if (! empty($tile['icon']))
                                    <span aria-hidden="true" class="absolute inset-0 grid place-items-center pb-5 {{ $accent }}">
                                        <x-icon :name="$tile['icon']" class="h-8 w-8 sm:h-10 sm:w-10" />
                                    </span>
                                @elseif (! empty($tile['image']))
                                    <img src="{{ $tile['image'] }}" alt="" loading="lazy" decoding="async"
                                         class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                @else
                                    <span aria-hidden="true" class="absolute inset-0 grid place-items-center pb-5 text-2xl font-extrabold {{ $accent }}">{{ mb_strtoupper(mb_substr($tile['name'], 0, 1)) }}</span>
                                @endif

                                @if (! empty($tile['new']))
                                    <span class="absolute left-0 top-0 rounded-br-lg bg-amber-400 px-1.5 text-[9px] font-bold leading-4 text-slate-900">{{ \App\Support\SiteText::get('home.cards.new') }}</span>
                                @endif

                                @if (! empty($tile['starts']))
                                    {{-- Events: a small live countdown on the poster. --}}
                                    <span class="absolute right-1 top-1 rounded-md bg-slate-900/85 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-white" wire:ignore data-countdown
                                          x-data="{ at: Date.parse(@js($tile['starts'])), left: 0, timer: null,
                                                    tick() { this.left = Math.max(0, Math.floor((this.at - Date.now()) / 1000)); },
                                                    init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000); },
                                                    destroy() { clearInterval(this.timer); },
                                                    pad(n) { return String(n).padStart(2, '0'); } }"
                                          x-text="left > 0 ? Math.floor(left / 86400) + 'd ' + pad(Math.floor(left % 86400 / 3600)) + 'h ' + pad(Math.floor(left % 3600 / 60)) + 'm ' + pad(left % 60) + 's' : @js(\App\Support\SiteText::get('home.card.events.on_now'))">&nbsp;</span>
                                @endif

                                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-white via-white/95 to-white/0 px-1.5 pb-1 pt-3">
                                    <span class="line-clamp-1 text-[10px] font-semibold leading-tight text-slate-900 sm:text-xs">{{ $tile['name'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>
</section>
