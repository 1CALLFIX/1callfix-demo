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
     | Social links are admin-configured, one Setting per network. A network
     | with no URL set renders NOTHING — the same "no data, no element"
     | rule the homepage sections follow. So this row is empty until the
     | brand fills in real handles, never a run of `#` links.
     */
    $socialLinks = collect([
        ['key' => 'branding.social_x', 'label' => 'X', 'path' => 'M13.6 10.6 20.9 2h-1.7l-6.3 7.4L7.8 2H2l7.7 11.2L2 22h1.7l6.7-7.9 5.4 7.9H22l-8-11.4Zm-2.4 2.8-.8-1.1L4.3 3.3h2.6l5 7.2.8 1.1 6.5 9.3h-2.6l-5.3-7.6Z'],
        ['key' => 'branding.social_facebook', 'label' => 'Facebook', 'path' => 'M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7A10 10 0 0 0 22 12Z'],
        ['key' => 'branding.social_instagram', 'label' => 'Instagram', 'path' => 'M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.3 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.1.4.3 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.3 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.1-1 .3-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.3-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.1-.4-.3-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.3-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.1 1-.3 2.2-.4C8.4 2.2 8.8 2.2 12 2.2Zm0 1.8c-3.1 0-3.5 0-4.7.1-1.1.1-1.7.2-2.1.4-.5.2-.9.4-1.3.8-.4.4-.6.8-.8 1.3-.2.4-.3 1-.4 2.1-.1 1.2-.1 1.6-.1 4.7s0 3.5.1 4.7c.1 1.1.2 1.7.4 2.1.2.5.4.9.8 1.3.4.4.8.6 1.3.8.4.2 1 .3 2.1.4 1.2.1 1.6.1 4.7.1s3.5 0 4.7-.1c1.1-.1 1.7-.2 2.1-.4.5-.2.9-.4 1.3-.8.4-.4.6-.8.8-1.3.2-.4.3-1 .4-2.1.1-1.2.1-1.6.1-4.7s0-3.5-.1-4.7c-.1-1.1-.2-1.7-.4-2.1-.2-.5-.4-.9-.8-1.3-.4-.4-.8-.6-1.3-.8-.4-.2-1-.3-2.1-.4-1.2-.1-1.6-.1-4.7-.1Zm0 3.1a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8Zm0 8a3.1 3.1 0 1 0 0-6.2 3.1 3.1 0 0 0 0 6.2Zm6.3-8.2a1.15 1.15 0 1 1-2.3 0 1.15 1.15 0 0 1 2.3 0Z'],
        ['key' => 'branding.social_linkedin', 'label' => 'LinkedIn', 'path' => 'M6.9 21H3.4V9h3.5v12ZM5.1 7.4A2 2 0 1 1 5.1 3.4a2 2 0 0 1 0 4ZM21 21h-3.5v-5.8c0-1.4 0-3.2-1.9-3.2s-2.2 1.5-2.2 3.1V21H9.9V9h3.3v1.6h.1c.5-.9 1.6-1.9 3.4-1.9 3.6 0 4.3 2.4 4.3 5.5V21Z'],
        ['key' => 'branding.social_youtube', 'label' => 'YouTube', 'path' => 'M23.5 6.5a3 3 0 0 0-2.1-2.1C19.5 4 12 4 12 4s-7.5 0-9.4.4A3 3 0 0 0 .5 6.5 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.5 3 3 0 0 0 2.1 2.1C4.5 20 12 20 12 20s7.5 0 9.4-.4a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.5ZM9.6 15.6V8.4l6.2 3.6-6.2 3.6Z'],
        ['key' => 'branding.social_whatsapp', 'label' => 'WhatsApp', 'path' => 'M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-2.9.8.8-2.8-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.2.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.4 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3c-.3.3-1 1-1 2.3s1 2.7 1.1 2.9c.1.2 2 3.1 4.9 4.3 1.8.8 2.5.9 3.4.7.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.2-.2-.5-.3Z'],
    ])->map(fn ($s) => $s + ['url' => \App\Models\Setting::get($s['key'], null)])
      ->filter(fn ($s) => filled($s['url']))
      ->values();
@endphp

<footer class="mb-bottom-nav border-t border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">

            <div class="sm:col-span-2 md:col-span-3 lg:col-span-1">
                <div class="flex items-center gap-2">
                    <span aria-hidden="true"
                          class="grid h-9 w-9 place-items-center rounded-xl bg-blue-600 text-sm font-bold text-white shadow-sm shadow-blue-600/30">
                        {{ \Illuminate\Support\Str::of($platformName)->substr(0, 1)->upper() }}
                    </span>
                    <span class="text-lg font-bold tracking-tight text-slate-900">{{ $platformName }}</span>
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

        <div class="mt-10 space-y-1 border-t border-slate-200 pt-6">
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
    </div>
</footer>
