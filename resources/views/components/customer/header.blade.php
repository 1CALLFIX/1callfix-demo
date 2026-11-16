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
            <div class="flex min-w-0 shrink items-center gap-1.5 sm:flex-1 sm:gap-2">
                <div class="flex min-w-0 items-center sm:flex-1 sm:rounded-full sm:border sm:border-slate-200 sm:bg-white sm:py-1 sm:pl-1 sm:pr-1 sm:shadow-md sm:shadow-slate-900/5 sm:transition sm:duration-200 sm:focus-within:border-blue-400 sm:focus-within:shadow-lg sm:focus-within:shadow-blue-600/10 sm:hover:shadow-lg">
                    {{-- Owner 2026-11-16: the location is only as wide as the city name (it shrinks to fit), and the
                         search field takes every bit of room that is left. --}}
                    <div class="min-w-0 sm:flex-none">
                        <livewire:customer.location-picker />
                    </div>
                    <div aria-hidden="true" class="mx-1 hidden h-6 w-px shrink-0 bg-slate-200 sm:block"></div>
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

                @guest
                    <a href="{{ route('customer.login') }}"
                       class="hidden sm:order-9 sm:inline-flex min-h-11 items-center whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        {{ $t('header.sign_in') }}
                    </a>
                @endguest

                @if ($partnerCta)
                    <a href="{{ route('customer.partners') }}"
                       class="hidden md:inline-flex min-h-10 items-center whitespace-nowrap rounded-lg border border-blue-600 px-3 text-sm font-semibold text-blue-700 transition hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        {{ $partnerCta }}
                    </a>
                @endif

                {{-- Primary CTA, visible from `sm` up (it had been hidden below `xl`, and was
                     dropped when the header was decluttered). Mobile keeps the bottom bar. --}}
                <a href="{{ route('customer.services.index') }}"
                   class="hidden sm:inline-flex min-h-10 items-center whitespace-nowrap rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    {{ $t('header.book_now') }}
                </a>

                {{-- Menu: hamburger-style button opening a dropdown. Alpine ships
                     with Livewire; closes on outside click, Escape and navigation. --}}
                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu" aria-label="Open menu"
                            class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        @auth
                            <span aria-hidden="true" class="text-sm font-semibold">{{ \Illuminate\Support\Str::of(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                        @else
                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                        @endauth
                    </button>

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
                                $groups[] = [$t('header.menu.group_account'), array_merge(
                                    [[$t('header.sign_in'), route('customer.login'), 'user']], $walletItem, $couponItem, $earnItems,
                                )];
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

                        @foreach ($groups as [$groupLabel, $groupItems])
                            <div @class(['pt-2', 'mt-1 border-t border-slate-100' => ! $loop->first || auth()->check()])>
                                <p class="px-4 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $groupLabel }}</p>
                                @foreach ($groupItems as $groupItem)
                                    @php [$label, $href, $icon] = $groupItem; $external = $groupItem[3] ?? false; @endphp
                                    <a href="{{ $href }}" role="menuitem" @if ($external) target="_blank" rel="noopener nofollow" @endif
                                       class="flex min-h-11 items-center gap-3 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-blue-600">
                                        <x-icon :name="$icon" class="h-5 w-5 shrink-0 text-slate-400" />
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
