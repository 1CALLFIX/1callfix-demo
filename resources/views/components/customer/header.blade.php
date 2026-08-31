@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');

    /*
     | Primary navigation. Services, Categories and Offers now point at real
     | Phase C screens; anything whose real screen still belongs to a later
     | phase routes to customer.coming-soon rather than a dead `#` href or a
     | stub that pretends to work — see routes/web.php.
     |
     | The vertical switcher (Parcel, Taxi, Rental, Hotels, Marketplace) is
     | deliberately absent. Those verticals have real backends but no customer
     | screens, Services is the launch experience, and a nav row of links to
     | placeholders would make the app look larger and emptier at the same
     | time. Adding one later is a change to this array plus its routes —
     | nothing about the layout, header or catalog architecture assumes a
     | single vertical.
     */
    $primaryNav = [
        ['label' => 'Services', 'route' => 'customer.services.index', 'param' => null],
        ['label' => 'Categories', 'route' => 'customer.categories.index', 'param' => null],
        ['label' => 'Offers', 'route' => 'customer.offers', 'param' => null],
        ['label' => 'How It Works', 'route' => 'customer.how-it-works', 'param' => null],
        ['label' => 'Help', 'route' => 'customer.help', 'param' => null],
    ];
@endphp

<header class="sticky top-0 z-40 bg-white border-b border-slate-200">
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {{-- gap-2 at `lg`, back to gap-4 at `xl`: 1024–1279px is the tightest
             band in this header — the desktop primary nav has appeared (~400px)
             but the viewport has not yet grown enough to carry it alongside the
             brand, location, account and CTA. Measured at 15px over. Tightening
             the gaps recovers it without removing a single navigation target,
             which every other option here would have. --}}
        <div class="flex h-16 items-center justify-between gap-4 lg:gap-2 xl:gap-4">

            {{-- Brand --}}
            <a href="{{ route('customer.home') }}"
               class="flex min-h-11 shrink-0 items-center gap-2 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                <span aria-hidden="true"
                      class="grid h-9 w-9 place-items-center rounded-lg bg-slate-900 text-sm font-bold text-white">
                    {{ \Illuminate\Support\Str::of($platformName)->substr(0, 1)->upper() }}
                </span>
                <span class="text-lg font-bold tracking-tight">{{ $platformName }}</span>
            </a>

            {{-- Desktop primary navigation --}}
            <nav aria-label="Primary" class="hidden lg:flex items-center gap-1">
                @foreach ($primaryNav as $item)
                    @php
                        $href = $item['param']
                            ? route($item['route'], $item['param'])
                            : route($item['route']);
                        $isCurrent = url()->current() === $href;
                    @endphp
                    <a href="{{ $href }}"
                       @if ($isCurrent) aria-current="page" @endif
                       @class([
                           'rounded-md px-3 py-2 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
                           'bg-slate-100 text-slate-900' => $isCurrent,
                           'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $isCurrent,
                       ])>{{ $item['label'] }}</a>
                @endforeach
            </nav>

            {{-- Search is icon-triggered at every width now (no persistent
                 embedded box anywhere): this button toggles the panel below
                 the bar — full-width on mobile, a dropdown anchored under the
                 icon from `sm` up (resources/js/search-bar.js). Under `sm`
                 the fixed bottom navigation already carries a full-width
                 Search item, so the button starts at `sm`. --}}
            <button type="button" data-search-toggle aria-expanded="false" aria-controls="header-search-drawer"
                    class="hidden min-h-11 items-center rounded-md px-2 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:inline-flex">
                <span class="sr-only">Search for a service</span>
                <x-icon name="magnifying-glass" class="h-5 w-5" />
            </button>

            {{-- Right cluster. min-w-0 so the account name's truncate can
                 actually engage instead of forcing the row wider. --}}
            <div class="flex min-w-0 items-center gap-1 sm:gap-2">

                {{-- Location. Same trigger on every breakpoint; the label
                     collapses to the icon alone on small screens. --}}
                <livewire:customer.location-picker />

                {{-- Language. There is no translation infrastructure in this
                     application yet — no lang/ directory, no translation
                     files, and every writer of users.preferred_language
                     hardcodes 'en'. Rendering a working-looking switcher
                     would be fake functionality, so this states the real
                     current language and links to the honest placeholder.

                     2xl, not md: between 1024 and 1279px the primary nav has
                     already appeared and this item pushed the row past the
                     viewport (caught by the breakpoint probe in Phase B's
                     browser testing). Phase C added the header search at
                     `xl`, which took the remaining slack, so this moved out
                     one further stop. It is the least important item in the
                     cluster, so it is the one that waits for the width. --}}
                <a href="{{ route('customer.coming-soon', 'languages') }}"
                   class="hidden 2xl:inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                    <span aria-hidden="true">🌐</span>
                    <span>English</span>
                </a>

                {{-- Account --}}
                @auth
                    <div class="flex items-center gap-1">
                        <a href="{{ route('customer.account') }}"
                           class="inline-flex min-h-11 items-center gap-2 rounded-md px-2 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            <span aria-hidden="true"
                                  class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">
                                {{ \Illuminate\Support\Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
                            </span>
                            <span class="hidden sm:inline max-w-[10rem] truncate">{{ auth()->user()->name }}</span>
                        </a>
                        <form method="POST" action="{{ route('customer.logout') }}">
                            @csrf
                            <button type="submit"
                                    class="hidden sm:inline-flex min-h-11 items-center rounded-md px-3 py-2 text-sm font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                                Log out
                            </button>
                        </form>
                    </div>
                @else
                    {{-- whitespace-nowrap: without it "Sign in" wraps to two
                         lines the moment the row gets tight, which is worse
                         than the two characters of width it saves. --}}
                    <a href="{{ route('customer.login') }}"
                       class="inline-flex min-h-11 items-center whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        Sign in
                    </a>
                @endauth

                {{-- Services cart. Its own Livewire island so the count
                     badge updates live on `cart-updated` without a reload.
                     Renders nothing for a guest. --}}
                @auth
                    <livewire:customer.cart-count />
                @endauth

                {{-- Primary CTA. Booking itself is still Phase D, so this
                     sends the customer to the real catalog to choose a
                     service — the first genuine step of booking, and now a
                     real screen — rather than to a placeholder. The Book Now
                     button on a service's own page is where the Phase D
                     wizard will attach. --}}
                <a href="{{ route('customer.services.index') }}"
                   class="hidden sm:inline-flex min-h-11 items-center whitespace-nowrap rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                    Book a Service
                </a>
            </div>
        </div>

        {{-- The one search surface. Hidden until [data-search-toggle] reveals
             it: a full-width strip under the bar on mobile, a floating
             dropdown anchored to the header's right edge from `sm` up. This
             is now the ONLY search-bar instance the header renders — there
             is no persistent embedded box at any width. --}}
        <div id="header-search-drawer" data-search-drawer hidden
             class="border-t border-slate-200 bg-white px-4 py-3
                    sm:absolute sm:right-4 sm:top-full sm:z-50 sm:mt-2 sm:w-[26rem] sm:rounded-xl sm:border sm:border-slate-200 sm:border-t sm:px-4 sm:shadow-lg lg:right-6">
            <livewire:customer.search-bar />
        </div>
    </div>
</header>
