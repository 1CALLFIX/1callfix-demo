@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $cityLabel = \App\Models\Setting::get('branding.operating_city_label', null);
    $legalEntityLine = \App\Models\Setting::get('branding.legal_entity_line', null);

    /*
     | Only real, reachable destinations appear here. Legal links point at
     | the genuinely seeded content_pages rows (privacy-policy /
     | terms-and-conditions); everything whose screen belongs to a later
     | phase goes through customer.coming-soon rather than a dead link.
     */
    $columns = [
        'Company' => [
            ['label' => 'How It Works', 'href' => route('customer.how-it-works')],
            ['label' => 'Help & FAQs', 'href' => route('customer.help')],
        ],
        'Services' => [
            // Phase C: all three are real screens now. `route()` would have
            // gone on happily generating /coming-soon/services URLs after
            // those keys left the whitelist — the route's whereIn only
            // rejects them on the way IN — so these were dead links until
            // they were repointed here, not compile errors.
            ['label' => 'Browse services', 'href' => route('customer.services.index')],
            ['label' => 'Categories', 'href' => route('customer.categories.index')],
            ['label' => 'Offers', 'href' => route('customer.offers')],
        ],
        'For professionals' => [
            // Real landing page now (customer.partners) — hero, admin-managed
            // benefits, the registration walkthrough and a CTA into
            // /provider/register. Same CTA phrase the page itself uses.
            ['label' => 'Join as a Partner', 'href' => route('customer.partners')],
        ],
        'Legal' => [
            ['label' => 'Privacy Policy', 'href' => route('customer.privacy')],
            ['label' => 'Terms of Use', 'href' => route('customer.terms')],
        ],
    ];

    /*
     | Social links come from the admin-managed social_media_links table
     | (Settings → Platform / Branding). A platform with no URL renders NOTHING
     | — the same "no data, no element" rule the homepage sections follow.
     */
    $socialLinks = collect(\App\Models\SocialMediaLink::footerLinks());
    $creditLine = \App\Services\BrandingAssetService::creditLine();
@endphp

<footer class="mb-bottom-nav border-t border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
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

            @foreach ($columns as $heading => $links)
                <div>
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

        <div class="mt-10 flex flex-col gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:items-end sm:justify-between">
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
        </div>
            @if (filled($creditLine))
                {{-- U+FE0E asks for the text (not colour-emoji) heart so it takes the
                     red below in every browser instead of a platform emoji. --}}
                <p class="text-xs text-slate-500 sm:text-right">{!! str_replace('❤', '<span class="text-red-500">❤&#xFE0E;</span>', e($creditLine)) !!}</p>
            @endif
        </div>
    </div>
</footer>
