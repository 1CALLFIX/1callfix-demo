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
            <div class="min-w-0 shrink sm:flex sm:flex-1 sm:justify-center">
                <div class="flex min-w-0 items-center sm:w-full sm:max-w-lg sm:rounded-full sm:border sm:border-slate-200 sm:bg-white sm:py-1 sm:pl-1 sm:pr-1 sm:shadow-md sm:shadow-slate-900/5 sm:transition sm:duration-200 sm:focus-within:border-blue-400 sm:focus-within:shadow-lg sm:focus-within:shadow-blue-600/10 sm:hover:shadow-lg">
                    <div class="min-w-0 sm:flex-1 sm:basis-0">
                        <livewire:customer.location-picker />
                    </div>
                    <div aria-hidden="true" class="mx-1 hidden h-6 w-px shrink-0 bg-slate-200 sm:block"></div>
                    <div class="hidden min-w-0 flex-1 basis-0 sm:block">
                        <livewire:customer.search-bar :compact="true" :pill="true" />
                    </div>
                </div>
            </div>

            {{-- Right cluster (1CF-HEADER-HAMBURGER-001): location, cart and ONE
                 menu button. Services / Categories / Earnings / Partner
                 dashboard / Log out all live inside the menu, so the bar
                 stays uncluttered like the reference marketplaces. --}}
            <div class="ml-auto flex min-w-0 items-center gap-1 sm:ml-0 sm:gap-2">

                {{-- Languages are the Super Admin's (Admin → Languages): two on = one switch button, three or more = a small menu. --}}
                @php
                    $cfLanguages = \App\Support\Languages::active();
                    $cfLocale = app()->getLocale();
                    $cfLanguageLabel = fn (string $code) => match ($code) {
                        'en' => $t('language.to_en'),
                        'te' => $t('language.to_te'),
                        default => $cfLanguages[$code]['name'] ?? $code,
                    };
                @endphp
                @if (count($cfLanguages) === 2)
                    @php $other = collect(array_keys($cfLanguages))->first(fn ($c) => $c !== $cfLocale) ?? 'en'; @endphp
                    <form method="POST" action="{{ route('customer.language', $other) }}">
                        @csrf
                        <button type="submit" lang="{{ $other }}" data-testid="language-switch"
                                class="inline-flex min-h-10 items-center whitespace-nowrap rounded-full border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            {{ $cfLanguageLabel($other) }}
                        </button>
                    </form>
                @elseif (count($cfLanguages) > 2)
                    <details class="relative" data-testid="language-switch">
                        <summary aria-label="{{ $t('language.choose') }}"
                                 class="inline-flex min-h-10 cursor-pointer list-none items-center gap-1 whitespace-nowrap rounded-full border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 [&::-webkit-details-marker]:hidden">
                            <span lang="{{ $cfLocale }}">{{ $cfLanguages[$cfLocale]['name'] ?? 'English' }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </summary>
                        <div class="absolute end-0 z-50 mt-2 min-w-40 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                            @foreach ($cfLanguages as $code => $lang)
                                <form method="POST" action="{{ route('customer.language', $code) }}">
                                    @csrf
                                    <button type="submit" lang="{{ $code }}" @if ($lang['rtl']) dir="rtl" @endif
                                            @class(['flex w-full items-center rounded-lg px-3 py-2 text-start text-sm hover:bg-slate-50', 'font-semibold text-blue-700' => $code === $cfLocale, 'text-slate-700' => $code !== $cfLocale])>
                                        {{ $lang['name'] }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </details>
                @endif

                {{-- Shop Phase A: visitors have a cart too (their shop items, kept in the session). --}}
                <livewire:customer.cart-count />

                @guest
                    <a href="{{ route('customer.login') }}"
                       class="hidden sm:inline-flex min-h-11 items-center whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
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

                    <div x-show="open" style="display:none" x-transition.origin.top.right role="menu"
                         class="absolute right-0 top-full z-50 mt-2 max-h-[calc(100dvh-6rem)] w-64 overflow-y-auto rounded-2xl border border-slate-200 bg-white py-2 shadow-xl shadow-slate-900/10">
                        @auth
                            <div class="border-b border-slate-100 px-4 pb-3 pt-1">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                            </div>
                        @endauth

                        @php
                            $menu = [
                                [$t('header.menu.services'), route('customer.services.index')],
                                [$t('header.menu.categories'), \App\Support\Seo\PublicUrl::services()],
                            ];
                            // E-commerce Phase 2: the shop of the customer's current city, only where Commerce is on there.
                            $shopCity = app(\App\Services\Seo\CityContext::class)->current();
                            if ($shopCity && \App\Livewire\Customer\Shop\Home::shopLiveIn($shopCity)) {
                                $menu[] = [$t('header.menu.shop'), route('customer.shop.home', $shopCity->slug)];
                            }
                            if (auth()->check()) {
                                $menu[] = [$t('header.menu.bookings'), route('customer.orders.index')];
                                $menu[] = [$t('header.menu.saved'), route('customer.saved')];
                                // Parcel: shown while parcel is on somewhere, or for anyone who already has parcels.
                                if (app(\App\Services\ModuleActivationService::class)->isActiveAnywhere(\App\Support\Modules::PARCEL)
                                    || \App\Models\ParcelOrder::where('customer_id', auth()->id())->exists()) {
                                    $menu[] = [$t('header.menu.parcels'), route('customer.parcels.index')];
                                }
                                if (\App\Models\MarketplaceOrder::where('customer_id', auth()->id())->exists()) {
                                    $menu[] = [$t('header.menu.shop_orders'), route('customer.shop.orders')];
                                }
                                if (auth()->user()->holdsPartnerRole('commerce')) {
                                    $menu[] = [$t('header.menu.my_shop'), route('seller.dashboard')];
                                }
                                if (auth()->user()->fieldWorkerProfile?->hasCapability('parcel_rider')) {
                                    $menu[] = [$t('header.menu.rider'), route('rider.dashboard')];
                                }
                                if ($earningsRoute = \App\Support\CustomerEarningsNav::firstRoute(auth()->user())) {
                                    $menu[] = [$t('header.menu.earnings'), route($earningsRoute)];
                                }
                                if (auth()->user()->providerProfile) {
                                    $menu[] = [$t('header.menu.partner_dashboard'), route('provider.dashboard')];
                                }
                                $menu[] = [$t('header.menu.account'), route('customer.account')];
                            }
                            if ($partnerCta) {
                                $menu[] = [$partnerCta, route('customer.partners')];
                            }
                            $menu[] = [$t('header.menu.help'), route('customer.help')];
                            // Inside the Android app only: rate it on the Play Store.
                            if ($rateUrl = \App\Support\NativeApp::rateUrl('customer')) {
                                $menu[] = [$t('app.rate'), $rateUrl];
                            }
                            if (! auth()->check()) {
                                // Signed out: same tab switches, global scope only. The
                                // target is auth-gated, so this routes via sign-in.
                                $guestEarningsRoute = collect([
                                    'earnings.wallet_tab' => 'customer.earnings.wallet',
                                    'earnings.loyalty_tab' => 'customer.earnings.loyalty',
                                    'earnings.referral_tab' => 'customer.earnings.referrals',
                                ])->first(fn ($route, $switch) => \App\Support\EarningsSettings::customerTabOn($switch, []));
                                if ($guestEarningsRoute) {
                                    array_splice($menu, 2, 0, [[$t('header.menu.earnings'), route($guestEarningsRoute)]]);
                                }
                                $menu[] = [$t('header.sign_in'), route('customer.login')];
                            }
                            // Owner 2026-11-07: the footer's link columns are hidden on phones and in the apps, so they
                            // live here instead ("More", phones only), minus any link the menu already has.
                            $have = array_column($menu, 1);
                            $more = collect(\App\Support\PartnerPage\FooterLinks::groups())->flatten(1)
                                ->reject(fn ($l) => in_array($l['href'], $have, true))->unique('href')->values();
                        @endphp
                        @foreach ($menu as [$label, $href])
                            <a href="{{ $href }}" role="menuitem"
                               class="block px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900">{{ $label }}</a>
                        @endforeach
                        @if ($more->isNotEmpty())
                            <div class="mt-1 border-t border-slate-100 pt-1 sm:hidden" data-menu-more>
                                <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $t('header.menu.more') }}</p>
                                @foreach ($more as $l)
                                    <a href="{{ $l['href'] }}" role="menuitem" class="block px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">{{ $l['label'] }}</a>
                                @endforeach
                            </div>
                        @endif

                        @auth
                            <form method="POST" action="{{ route('customer.logout') }}" class="mt-1 border-t border-slate-100 pt-1">
                                @csrf
                                <button type="submit" role="menuitem"
                                        class="block w-full px-4 py-2.5 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900">{{ $t('header.menu.logout') }}</button>
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
