@props([
    'title' => null,
    'metaDescription' => null,
    'ogImage' => null,
    'ogType' => 'website',
    // Public marketing/catalogue pages opt in. Default is private (noindex, no
    // canonical) so any new page fails safe rather than leaking into search.
    'indexable' => false,
    // The title is already complete (home page): do not wrap it in the site title template.
    'rawTitle' => false,
    // Structured data blocks (arrays) for this page, rendered as JSON-LD.
    'schema' => [],
    // A page with its own top bar (the partner page) drops the customer search header and mobile bottom nav. The footer stays.
    'minimalChrome' => false,
])

@php
    $platformName = \App\Services\Seo\SeoSettings::siteName();
    $brandShareLogo = app(\App\Services\BrandingAssetService::class)->url('logo_display_path');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) resolve to a
         real value on notched/gesture-bar devices; without it the sticky
         bottom navigation sits underneath the iOS home indicator. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @php
        // Title and description: the page's own value, else the admin's SEO settings, else the built-in fallback.
        $pageTitle = $rawTitle ? ($title ?: $platformName) : \App\Services\Seo\SeoSettings::renderTitle($title);
        $pageDescription = $metaDescription ?: \App\Services\Seo\SeoSettings::defaultDescription();
        $verification = \App\Services\Seo\SeoSettings::verification();
    @endphp
    <meta name="description" content="{{ $pageDescription }}">
    <title>{{ $pageTitle }}</title>
    @if ($verification['google'])
        <meta name="google-site-verification" content="{{ $verification['google'] }}">
    @endif
    @if ($verification['bing'])
        <meta name="msvalidate.01" content="{{ $verification['bing'] }}">
    @endif

    {{-- F2: canonical + Open Graph/Twitter. Canonical is the current URL with the
         query string dropped, so ?utm_*/gclid/fbclid/?page= variants (F1 capture) all
         consolidate on one address. Absolute URLs only — crawlers ignore relative ones. --}}
    @php
        $canonicalUrl = \App\Support\Seo::canonicalUrl();
        // Share image: the page's own, else the admin's default share image, else the logo. Always on the canonical host.
        $shareImage = $ogImage ?: (\App\Services\Seo\SeoSettings::defaultImage() ?: ($brandShareLogo ?? null));
    @endphp
    @if ($indexable)
        <meta name="robots" content="index, follow">
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <meta property="og:site_name" content="{{ $platformName }}">
        <meta property="og:type" content="{{ $ogType }}">
        <meta property="og:title" content="{{ $title ?: $platformName }}">
        <meta property="og:description" content="{{ $pageDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:locale" content="en_IN">
        @if ($shareImage)
            <meta property="og:image" content="{{ \App\Support\Seo::absoluteUrl($shareImage) }}">
        @endif
        <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $title ?: $platformName }}">
        <meta name="twitter:description" content="{{ $pageDescription }}">
        @foreach ($schema as $block)
            <script type="application/ld+json">{!! json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endforeach
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif

    {{-- PWA foundation: the manifest makes the app installable, theme-color
         paints the mobile browser chrome in the brand blue. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#2563eb">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $platformName }}">
    @php
        $brandAssets = app(\App\Services\BrandingAssetService::class);
        $faviconUrl = $brandAssets->url('favicon_path');
        $appleTouchUrl = $brandAssets->url('apple_touch_path');
    @endphp
    {{-- Admin-uploaded logo → generated favicon files; falls back to the bundled icons. --}}
    @if ($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}" type="{{ str_ends_with($faviconUrl, '.svg') ? 'image/svg+xml' : 'image/png' }}"@if (! str_ends_with($faviconUrl, '.svg')) sizes="32x32"@endif>
    @else
        <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @endif
    <link rel="apple-touch-icon" href="{{ $appleTouchUrl ?: asset('icons/icon-maskable.svg') }}">

    @fonts
    {{-- The real Vite pipeline, deliberately NOT the cdn.tailwindcss.com
         script layouts/admin.blade.php still loads. That CDN build compiles
         Tailwind in the browser on every page load — a development-only
         tool, and a material first-paint cost on a consumer-facing page. --}}
    {{-- push-notifications.js: FCM web token registration (Phase 2). Inert
         no-op unless VITE_FIREBASE_* + VITE_FIREBASE_VAPID_KEY are built in. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js'])
    @livewireStyles

    {{-- 1CF-HOMESCREEN-UX-001: the SAME browser key config('services.google_maps.key')
         already exposes to the admin Maps JavaScript API (resources/views/layouts/admin.blade.php)
         — never a second/unrestricted key. resources/js/places-autocomplete.js reads this
         and stays a silent no-op (static zone list only) when it is blank. The key itself is
         never written into a JS bundle file; it only ever reaches the page this way, exactly
         like the admin layout already does it. --}}
    @if (config('services.google_maps.key'))
        <script>
            window.CF_GOOGLE_PLACES = {
                key: @js(config('services.google_maps.key')),
                regionCode: @js(config('services.google_maps.places_region')),
            };
        </script>
    @endif
</head>
<body class="min-h-full bg-white text-slate-900 antialiased flex flex-col">
    {{-- Keyboard users tabbing from the top of the document can jump past the
         whole header into the page content (WCAG 2.1 AA 2.4.1). Visually
         hidden until focused — see .skip-link in resources/css/app.css. --}}
    <a href="#customer-main" class="skip-link">Skip to main content</a>

    @unless ($minimalChrome)
        <x-customer.header />
    @endunless

    <main id="customer-main" tabindex="-1" class="flex-1 focus:outline-none">
        {{ $slot }}
    </main>

    <x-customer.footer />

    {{-- Mobile-only sticky navigation. Last in source order because it is
         fixed-position chrome, not document content. --}}
    @unless ($minimalChrome)
        <x-customer.bottom-nav />
    @endunless

    @livewireScripts
    {{-- Auth screens push the Firebase JS SDK bundle here so it loads only
         where phone-OTP / Google sign-in is actually used, not on every
         customer page. --}}
    @stack('scripts')
</body>
</html>
