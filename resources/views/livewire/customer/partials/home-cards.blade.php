{{--
    Business cards (owner samples 2026-10-31): one card per business — heading + "View all", a row of icons, one offer
    line + "More". Same shape every time so the page is learnt once. One column on a phone, two side by side from `lg`.
    Data, order and words: Home::moduleCards() + HomeCards + SiteText (all Super Admin).
--}}
@php
    $cols = \App\Support\HomeCards::icons() === 8 ? 'grid-cols-4 sm:grid-cols-8' : 'grid-cols-4';
    $fit = \App\Support\SiteControls::imageClasses();
@endphp
<section aria-label="{{ \App\Support\SiteText::get('home.categories.sr') }}" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8" data-testid="home-cards">
    <div @class(['grid gap-4', 'lg:grid-cols-2' => count($cards) > 1])>
        @foreach ($cards as $card)
            <article class="flex flex-col rounded-3xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-900/5 sm:p-5"
                     aria-labelledby="card-{{ $card['key'] }}" data-card="{{ $card['key'] }}" wire:key="card-{{ $card['key'] }}">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="card-{{ $card['key'] }}" class="truncate text-base font-bold tracking-tight text-slate-900 sm:text-lg">{{ $card['title'] }}</h2>
                    <a href="{{ $card['url'] }}" wire:navigate
                       class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded text-sm font-semibold text-blue-700 hover:text-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        <span aria-hidden="true">{{ \App\Support\SiteText::get('home.cards.view_all') }}</span>
                        <span class="sr-only">{{ \App\Support\SiteText::get('home.cards.view_all') }} — {{ $card['title'] }}</span>
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>

                <ul class="mt-3 grid {{ $cols }} gap-x-2 gap-y-4">
                    @foreach ($card['tiles'] as $tile)
                        <li>
                            <a href="{{ $tile['url'] }}" wire:navigate
                               class="group flex h-full flex-col items-center gap-1.5 rounded-2xl p-1 text-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                <span aria-hidden="true" class="relative grid h-14 w-14 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-blue-50 to-sky-100 text-blue-700 ring-1 ring-inset ring-blue-100 transition duration-200 group-hover:-translate-y-0.5 group-hover:shadow-md group-hover:shadow-blue-600/15 sm:h-16 sm:w-16"
                                      @if (! empty($tile['tint'])) style="background: {{ $tile['tint'] }}" @endif>
                                    @if (! empty($tile['icon']))
                                        <x-icon :name="$tile['icon']" class="h-7 w-7" />
                                    @else
                                        <x-customer.initial :name="$tile['name']" />
                                        @if (! empty($tile['image']))
                                            <img src="{{ $tile['image'] }}" alt="" loading="lazy" decoding="async" width="64" height="64"
                                                 class="absolute inset-0 h-full w-full p-1 {{ $fit }}">
                                        @endif
                                    @endif
                                    @if (! empty($tile['new']))
                                        <span class="absolute right-0 top-0 rounded-bl-lg bg-amber-400 px-1 text-[9px] font-bold leading-4 text-slate-900">{{ \App\Support\SiteText::get('home.cards.new') }}</span>
                                    @endif
                                </span>
                                <span class="line-clamp-2 text-[11px] font-medium leading-tight text-slate-800 sm:text-xs">{{ $tile['name'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($card['line'])
                    <a href="{{ $card['line_url'] }}" wire:navigate data-testid="card-line"
                       class="mt-4 flex min-h-11 items-center justify-between gap-3 rounded-2xl bg-gradient-to-r from-blue-50 to-white px-3 py-2 ring-1 ring-inset ring-blue-100 transition hover:ring-blue-300">
                        <span class="line-clamp-2 text-xs font-medium text-slate-700 sm:text-sm">{{ $card['line'] }}</span>
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-white px-3 py-1 text-xs font-bold text-blue-700 shadow-sm ring-1 ring-blue-100">
                            {{ \App\Support\SiteText::get('home.cards.more') }} <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                        </span>
                    </a>
                @endif
            </article>
        @endforeach
    </div>
</section>
