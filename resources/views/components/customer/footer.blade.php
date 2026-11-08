@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $cityLabel = \App\Models\Setting::get('branding.operating_city_label', null);
    $legalEntityLine = \App\Models\Setting::get('branding.legal_entity_line', null);

    // Link groups: the code default (App\Support\PartnerPage\FooterLinks::defaults, the groups that used to be
    // listed here) unless the admin saved a valid list. CMS pages ticked "show in footer" are appended to Company.
    $columns = \App\Support\PartnerPage\FooterLinks::groups();
    $contactLine = \App\Support\PartnerPage\FooterLinks::contactLine();

    /*
     | Social links come from the admin-managed social_media_links table
     | (Settings → Platform / Branding). A platform with no URL renders NOTHING
     | — the same "no data, no element" rule the homepage sections follow.
     */
    $socialLinks = collect(\App\Models\SocialMediaLink::footerLinks());
    $creditLine = \App\Services\BrandingAssetService::creditLine();
    // Owner 2026-11-07: on phones the link columns move into the ☰ menu ("More"), so the footer is just the brand,
    // social icons, rights and credit line. Phones: hidden by CSS (the links stay in the page for search engines).
    // Inside the Android apps: not rendered at all, and no "Get the app" buttons.
    $inApp = \App\Support\NativeApp::inApp();
@endphp

<footer class="mb-bottom-nav border-t border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">

            <div class="sm:col-span-2 md:col-span-3 lg:col-span-1">
                <div class="flex items-center gap-2">
                    <x-customer.brand-mark img-class="h-14 w-14" />
                </div>
                <p class="mt-4 max-w-xs text-sm text-slate-600">
                    {{ $cityLabel
                        ? 'Trusted local professionals for your home and business in '.$cityLabel.'.'
                        : 'Trusted local professionals for your home and business.' }}
                </p>
                @if (filled($contactLine))
                    <p class="mt-2 max-w-xs text-sm text-slate-600">{{ $contactLine }}</p>
                @endif

                @if ($socialLinks->isNotEmpty())
                    <ul class="mt-5 flex flex-wrap gap-2">
                        @foreach ($socialLinks as $social)
                            <li>
                                <a href="{{ $social['url'] }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="grid h-10 w-10 place-items-center rounded-full border border-slate-300 text-slate-500 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                    <span class="sr-only">{{ $platformName }} on {{ $social['label'] }}</span>
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                                        <path d="{{ $social['path'] }}" />
                                    </svg>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @foreach ($inApp ? [] : $columns as $heading => $links)
                <div class="hidden sm:block" data-footer-column>
                    <h2 class="text-sm font-semibold text-slate-900">{{ $heading }}</h2>
                    {{-- inline-flex + min-h-11 rather than a bare inline <a>:
                         as plain text links these were 18px tall, well under
                         the 44px touch target this design targets (measured,
                         not assumed — the breakpoint probe reported it). --}}
                    <ul class="mt-2">
                        @foreach ($links as $link)
                            <li>
                                <a href="{{ $link['href'] }}"
                                   class="inline-flex min-h-11 items-center rounded text-sm text-slate-600 underline-offset-4 transition hover:text-blue-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                    {{ $link['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- App (owner 2026-11-08): always the Play Store badge (Partner page → App links overrides the link), plus the
             iPhone link only if one was typed; "Get the app" opens /app with the QR code and iPhone steps. --}}
        @unless ($inApp)
            <div class="mt-8 flex flex-wrap items-center gap-3" data-testid="footer-app">
                <x-customer.play-badge size="sm" />
                @if ($iosUrl = \App\Support\AppLinks::ios())
                    <a href="{{ $iosUrl }}" rel="noopener" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:border-blue-400 hover:text-blue-700">{{ \App\Support\SiteText::get('get_app.ios_button') }}</a>
                @endif
                <a href="{{ route('customer.get-app') }}" class="text-sm font-semibold text-blue-700 underline-offset-4 hover:underline">{{ \App\Support\SiteText::get('get_app.footer_more') }}</a>
            </div>
        @endunless

        <div class="mt-8 flex flex-col gap-3 border-t sm:mt-10 border-slate-200 pt-6 sm:flex-row sm:items-end sm:justify-between">
        <div class="space-y-1">
            <p class="text-xs text-slate-500">
                &copy; {{ now()->year }} {{ $platformName }}. All rights reserved.
            </p>
            {{-- Registered-entity / CIN line, shown verbatim from a Setting
                 when the brand has filled one in. Never assembled or guessed
                 here — an incorrect legal identifier is worse than none. --}}
            @if (filled($legalEntityLine))
                <p class="text-xs text-slate-400">{{ $legalEntityLine }}</p>
            @endif
            <x-ui.app-version />
        </div>
            @if (filled($creditLine))
                {{-- U+FE0E asks for the text (not colour-emoji) heart (any FE0E/FE0F the admin typed is normalised) so it takes the
                     red below in every browser instead of a platform emoji. --}}
                <p class="text-xs text-slate-500 sm:text-right">{!! preg_replace('/❤[\x{FE0E}\x{FE0F}]?/u', '<span class="text-red-500">❤&#xFE0E;</span>', e($creditLine)) !!}</p>
            @endif
        </div>
    </div>
</footer>
