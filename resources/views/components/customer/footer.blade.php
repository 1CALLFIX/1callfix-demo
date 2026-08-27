@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $cityLabel = \App\Models\Setting::get('branding.operating_city_label', null);

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
        'Legal' => [
            ['label' => 'Privacy Policy', 'href' => route('customer.privacy')],
            ['label' => 'Terms of Use', 'href' => route('customer.terms')],
        ],
    ];
@endphp

<footer class="mb-bottom-nav border-t border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 md:grid-cols-4">

            <div>
                <div class="flex items-center gap-2">
                    <span aria-hidden="true"
                          class="grid h-9 w-9 place-items-center rounded-lg bg-slate-900 text-sm font-bold text-white">
                        {{ \Illuminate\Support\Str::of($platformName)->substr(0, 1)->upper() }}
                    </span>
                    <span class="text-lg font-bold tracking-tight text-slate-900">{{ $platformName }}</span>
                </div>
                <p class="mt-4 max-w-xs text-sm text-slate-600">
                    {{ $cityLabel
                        ? 'Trusted local professionals for your home and business in '.$cityLabel.'.'
                        : 'Trusted local professionals for your home and business.' }}
                </p>
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
                                   class="inline-flex min-h-11 items-center rounded text-sm text-slate-600 underline-offset-4 transition hover:text-slate-900 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                                    {{ $link['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="mt-10 border-t border-slate-200 pt-6">
            <p class="text-xs text-slate-500">
                &copy; {{ now()->year }} {{ $platformName }}. All rights reserved.
            </p>
        </div>
    </div>
</footer>
