@php
    $t = fn (string $key) => \App\Support\SiteText::get($key);
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    // "Become a partner" (Super Admin: Admin → Homepage & sign-up): for anyone signed out, and for a signed-in
    // account that can still add a partner role — one account, several roles.
    $partnerCta = \App\Support\SiteControls::headerPartnerButton() && (! auth()->check() || auth()->user()->canAddAPartnerRole())
        ? \App\Support\SiteControls::headerPartnerLabel() : null;

@endphp

<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 shadow-sm shadow-slate-900/5 backdrop-blur-md supports-[backdrop-filter]:bg-white/75">
    {{-- Slim brand accent line (1CF-TOPBAR-POLISH-001). --}}
    <div aria-hidden="true" class="h-1 w-full bg-gradient-to-r from-blue-600 via-indigo-500 to-sky-400"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {{-- gap-2 at `lg`, back to gap-4 at `xl`: kept from when this bar
             carried a five-link primary nav and 1024–1279px was 15px over.
             The nav is two links now and the row has ample slack, but the
             tighter `lg` gap is harmless and leaves headroom for the "Book a
             Service" CTA, which could now be promoted from `xl` to `lg`. --}}
        <div class="flex h-16 items-center justify-between gap-4 lg:gap-2 xl:gap-4">

            {{-- Brand --}}
            <a href="{{ route('customer.home') }}"
               class="flex min-h-11 min-w-0 items-center gap-2 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                {{-- Owner 2026-11-05: on a phone only the logo shows (the name was cut to "1Call..."); the name
                     beside it comes back from `sm` up. The logo's alt text still carries the name. --}}
                <x-customer.brand-mark name-class="hidden min-w-0 truncate text-base sm:inline sm:text-lg" />
            </a>

            {{-- Location + search as ONE elevated pill from `sm` up (1CF-TOPBAR-POLISH-001): pin + area
                 on the left, a divider, the search field and a round blue search button on the right.
                 On phones the location is a compact field next to the brand and search is the row below. --}}
            <div class="flex min-w-0 shrink items-center gap-1.5 sm:flex-1 sm:gap-3">
                {{-- Owner 2026-11-16: the location is its OWN box (Amazon / Flipkart), as wide as its text needs, so the
                     area and city ("Mulapet, Nellore") are always readable; the search pill takes all the room left. --}}
                <div class="min-w-0 sm:shrink-0">
                    <livewire:customer.location-picker />
                </div>
                <div class="flex min-w-0 items-center sm:flex-1 sm:rounded-full sm:border sm:border-slate-200 sm:bg-white sm:py-1 sm:pl-1 sm:pr-1 sm:shadow-md sm:shadow-slate-900/5 sm:transition sm:duration-200 sm:focus-within:border-blue-400 sm:focus-within:shadow-lg sm:focus-within:shadow-blue-600/10 sm:hover:border-blue-300 sm:hover:shadow-lg">
                    <div class="hidden min-w-0 flex-1 sm:block">
                        <livewire:customer.search-bar :compact="true" :pill="true" />
                    </div>
                </div>

                {{-- Language beside the search bar (Amazon pattern). Admin → Languages decides which are on. --}}
                <x-customer.language-menu />
            </div>

            {{-- Right cluster (1CF-HEADER-HAMBURGER-001): location, cart and ONE
                 menu button. Services / Categories / Earnings / Partner
                 dashboard / Log out all live inside the menu, so the bar
                 stays uncluttered like the reference marketplaces. --}}
            <div class="ml-auto flex min-w-0 items-center gap-1 sm:ml-0 sm:gap-2">

                {{-- Shop Phase A: visitors have a cart too (their shop items, kept in the session). --}}
                {{-- Owner 2026-11-15: from `sm` up, Sign in then Cart sit at the far right end (Amazon / Flipkart
                     pattern) and the search pill is wider. Phones keep their order. --}}
                <div class="flex items-center sm:order-10"><livewire:customer.cart-count /></div>

                @if ($partnerCta)
                    <a href="{{ route('customer.partners') }}"
                       class="hidden md:inline-flex min-h-10 items-center whitespace-nowrap rounded-lg border border-blue-600 px-3 text-sm font-semibold text-blue-700 transition hover:-translate-y-0.5 hover:bg-blue-600 hover:text-white hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        {{ $partnerCta }}
                    </a>
                @endif

                {{-- "Book Now" only ever meant services; with shop, food, hotels, rentals and more it is off by default.
                     Super Admin → Site controls → Website header turns it back on. --}}
                @if (\App\Support\SiteControls::headerBookButton())
                    <a href="{{ route('customer.services.index') }}"
                       class="hidden sm:inline-flex min-h-10 items-center whitespace-nowrap rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        {{ $t('header.book_now') }}
                    </a>
                @endif

                {{-- ONE account control (Flipkart / Amazon): "Sign in ▾" (or the customer's name) from `sm` up, a round
                     button on phones. Rest the mouse on it and the menu opens; move away and it closes. Tap/click and
                     Escape work too, and phones only ever tap. Super Admin can turn the hover off (Site controls). --}}
                @php $menuHover = \App\Support\SiteControls::headerMenuHover(); @endphp
                <div class="relative sm:order-9" x-data="{ open: false, t: null,
                        canHover() { return {{ $menuHover ? 'true' : 'false' }} && window.matchMedia('(hover: hover) and (min-width: 640px)').matches },
                        enter() { if (this.canHover()) { clearTimeout(this.t); this.open = true } },
                        leave() { if (this.canHover()) { clearTimeout(this.t); this.t = setTimeout(() => this.open = false, 180) } } }"
                     @mouseenter="enter()" @mouseleave="leave()" @keydown.escape.window="open = false" @click.outside="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu" aria-label="Open menu" data-account-trigger
                            class="group inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:w-auto sm:gap-2 sm:rounded-lg sm:border-transparent sm:bg-transparent sm:px-3 sm:text-sm sm:font-semibold">
                        @auth
                            <span aria-hidden="true" class="grid h-8 w-8 place-items-center rounded-full bg-blue-600 text-sm font-semibold text-white sm:h-7 sm:w-7">{{ \Illuminate\Support\Str::of(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                            <span class="hidden max-w-[7rem] truncate sm:inline">{{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}</span>
                        @else
                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 sm:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                            <x-icon name="user" class="hidden h-5 w-5 sm:block" />
                            <span class="hidden whitespace-nowrap sm:inline">{{ $t('header.sign_in') }}</span>
                        @endauth
                        <span class="hidden transition sm:block" :class="open ? 'rotate-180' : ''"><x-icon name="chevron-down" class="h-4 w-4 text-slate-400 group-hover:text-blue-600" /></span>
                    </button>

                    {{-- Invisible bridge so the menu does not close while the mouse crosses the small gap. --}}
                    <div x-show="open" style="display:none" aria-hidden="true" class="absolute right-0 top-full h-3 w-full"></div>

                    {{-- Owner 2026-11-16: one structured account menu (Amazon / Flipkart pattern): a profile card, then
                         labelled groups with an icon on every line. All orders (services, parcels, shop, food, hotels,
                         halls, events) are ONE entry, "My Orders". Nothing here is repeated on the My account page. --}}
                    <div x-show="open" style="display:none" x-transition.origin.top.right role="menu"
                         class="absolute right-0 top-full z-50 mt-2 max-h-[calc(100dvh-6rem)] w-72 overflow-y-auto rounded-2xl border border-slate-200 bg-white pb-2 shadow-xl shadow-slate-900/10">
                        @php
                            $shopCity = app(\App\Services\Seo\CityContext::class)->current();
                            $groups = [];

                            // Earnings pages are direct entries here only (not repeated on My account): Wallet, Refer a friend and
                            // Earnings (loyalty points), each only while its tab is on for this customer (Super Admin's switches).
                            // Signed out: the same switches at global scope; the pages route via sign-in.
                            $earnScope = auth()->check() ? array_filter(['franchise_id' => auth()->user()->franchise_id, 'zone_id' => auth()->user()->zone_id]) : [];
                            $tabOn = fn (string $switch) => \App\Support\EarningsSettings::customerTabOn($switch, $earnScope);
                            $walletItem = $tabOn('earnings.wallet_tab') ? [[$t('header.menu.wallet'), route('customer.earnings.wallet'), 'wallet']] : [];
                            $couponItem = [[$t('header.menu.coupons'), route('customer.offers'), 'tag']];
                            $earnItems = [];
                            if ($tabOn('earnings.referral_tab')) {
                                $earnItems[] = [$t('header.menu.refer'), route('customer.earnings.referrals'), 'users'];
                            }
                            if ($tabOn('earnings.loyalty_tab')) {
                                $earnItems[] = [$t('header.menu.earnings'), route('customer.earnings.loyalty'), 'sparkles'];
                            }

                            if (auth()->check()) {
                                $mine = array_merge(
                                    [[$t('header.menu.orders'), route('customer.orders.index'), 'shopping-bag'], [$t('header.menu.saved'), route('customer.saved'), 'star']],
                                    $walletItem, $couponItem, $earnItems,
                                    [[$t('header.menu.account'), route('customer.account'), 'user']],
                                );
                                $groups[] = [$t('header.menu.group_account'), $mine];

                                // Only for people who run something on the app.
                                $work = [];
                                if (auth()->user()->holdsPartnerRole('commerce')) {
                                    $work[] = [$t('header.menu.my_shop'), route('seller.dashboard'), 'building'];
                                }
                                if (auth()->user()->fieldWorkerProfile?->hasCapability('parcel_rider')) {
                                    $work[] = [$t('header.menu.rider'), route('rider.dashboard'), 'activity'];
                                }
                                if (auth()->user()->providerProfile) {
                                    $work[] = [$t('header.menu.partner_dashboard'), route('provider.dashboard'), 'wrench'];
                                }
                                if ($work) {
                                    $groups[] = [$t('header.menu.group_work'), $work];
                                }
                            } else {
                                // Signed out: Sign in is the big button at the top of the menu (below), not a repeated row.
                                $groups[] = [$t('header.menu.group_account'), array_merge($walletItem, $couponItem, $earnItems)];
                            }

                            $more = [[$t('header.menu.categories'), \App\Support\Seo\PublicUrl::services(), 'squares']];
                            if ($partnerCta) {
                                $more[] = [$partnerCta, route('customer.partners'), 'megaphone'];
                            }
                            // Rate & Review (owner 2026-11-16): in a browser, the Google review page set in Admin → Google reviews
                            // (opens in a new tab); inside the Android app, the Play Store rating instead. Hidden while no link is set.
                            if (! \App\Support\NativeApp::inApp() && ($reviewUrl = \App\Services\Reviews\GoogleReviews::reviewUrl())) {
                                $more[] = [$t('header.menu.rate_review'), $reviewUrl, 'trophy', true];
                            }
                            if ($rateUrl = \App\Support\NativeApp::rateUrl('customer')) {
                                $more[] = [$t('app.rate'), $rateUrl, 'trophy'];
                            }
                            $more[] = [$t('header.menu.contact'), route('customer.contact'), 'phone'];
                            $more[] = [$t('header.menu.help'), route('customer.help'), 'chat'];
                            $groups[] = [$t('header.menu.group_more'), $more];
                            $callHref = \App\Support\InfoPages\ContactDetails::telHref();
                            $waHref = \App\Support\InfoPages\ContactDetails::whatsappHref();

                            // The footer's link columns are hidden on phones and in the apps, so they live here ("More",
                            // phones only), minus any link the menu already has.
                            $have = collect($groups)->flatMap(fn ($g) => array_column($g[1], 1))->all();
                            $footerLinks = collect(\App\Support\PartnerPage\FooterLinks::groups())->flatten(1)
                                ->reject(fn ($l) => in_array($l['href'], $have, true))->unique('href')->values();
                        @endphp

                        @auth
                            {{-- Profile card --}}
                            <div class="flex items-center gap-3 border-b border-slate-100 px-4 pb-3 pt-3">
                                <span aria-hidden="true" class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-blue-600 text-base font-semibold text-white">{{ \Illuminate\Support\Str::of(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                                    @if (auth()->user()->phone)
                                        <p class="truncate text-xs text-slate-500">{{ auth()->user()->phone }}</p>
                                    @endif
                                </div>
                            </div>
                        @endauth

                        @guest
                            {{-- Flipkart pattern: "New customer? Sign up" and one big Sign in button. --}}
                            <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                                <span class="text-sm text-slate-600">{{ $t('header.new_customer') }}</span>
                                <a href="{{ route('customer.signup') }}" role="menuitem" class="text-sm font-semibold text-blue-600 transition hover:text-blue-800 hover:underline">{{ $t('header.sign_up') }}</a>
                            </div>
                            <div class="px-4 pt-3">
                                <a href="{{ route('customer.login') }}" role="menuitem"
                                   class="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                    <x-icon name="user" class="h-5 w-5" />{{ $t('header.sign_in') }}
                                </a>
                            </div>
                        @endguest

                        @foreach ($groups as [$groupLabel, $groupItems])
                            <div class="mt-2 border-t border-slate-100 pt-2">
                                <p class="px-4 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $groupLabel }}</p>
                                @foreach ($groupItems as $groupItem)
                                    @php [$label, $href, $icon] = $groupItem; $external = $groupItem[3] ?? false; @endphp
                                    <a href="{{ $href }}" role="menuitem" @if ($external) target="_blank" rel="noopener nofollow" @endif
                                       class="group/item flex min-h-11 items-center gap-3 border-l-4 border-transparent px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-blue-500 hover:bg-blue-50 hover:pl-5 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-blue-600">
                                        <x-icon :name="$icon" class="h-5 w-5 shrink-0 text-slate-400 transition group-hover/item:scale-110 group-hover/item:text-blue-600" />
                                        <span class="truncate">{{ $label }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endforeach

                        {{-- Need help? One tap to call or WhatsApp, with the numbers Super Admin sets on the Contact page. --}}
                        @if ($callHref || $waHref)
                            <div class="mt-1 border-t border-slate-100 px-4 pb-1 pt-3">
                                <p class="pb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $t('header.menu.need_help') }}</p>
                                <div class="flex gap-2">
                                    @if ($callHref)
                                        <a href="{{ $callHref }}" role="menuitem"
                                           class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-blue-50 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                            <x-icon name="phone" class="h-5 w-5" />{{ $t('header.menu.call') }}
                                        </a>
                                    @endif
                                    @if ($waHref)
                                        <a href="{{ $waHref }}" target="_blank" rel="noopener nofollow" role="menuitem"
                                           class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-50 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600">
                                            <x-icon name="chat" class="h-5 w-5" />{{ $t('header.menu.whatsapp') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if ($footerLinks->isNotEmpty())
                            <div class="mt-1 border-t border-slate-100 pt-1 sm:hidden" data-menu-more>
                                <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $t('header.menu.more') }}</p>
                                @foreach ($footerLinks as $l)
                                    <a href="{{ $l['href'] }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">{{ $l['label'] }}</a>
                                @endforeach
                            </div>
                        @endif

                        @auth
                            <form method="POST" action="{{ route('customer.logout') }}" class="mt-1 border-t border-slate-100 pt-1">
                                @csrf
                                <button type="submit" role="menuitem"
                                        class="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900">
                                    <x-icon name="logout" class="h-5 w-5 shrink-0 text-slate-400" />
                                    {{ $t('header.menu.logout') }}
                                </button>
                            </form>
                        @endauth
                    </div>
                </div>
            </div>
        </div>

        {{-- Mobile search row. Under `sm` the field cannot fit in the bar
             beside the brand and account cluster, so it becomes a full-width
             second row — the same persistent-search pattern Urban Company
             uses on mobile. Same compact SearchBar island as the desktop
             one; the two are independent Livewire components and never share
             state. --}}
        <div class="border-t border-slate-200 px-4 py-2 sm:hidden">
            <livewire:customer.search-bar :compact="true" />
        </div>
    </div>
</header>
