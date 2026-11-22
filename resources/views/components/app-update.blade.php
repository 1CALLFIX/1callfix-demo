{{-- App Upgrade (owner 2026-11-22): inside our Android apps only, asks (or makes) phones on an older build update from
     the Play Store. Admin → Settings → App Upgrade; the check itself is public/js/app-update.js (plain file, no build).
     Pages are cached per app, so a browser's copy never carries this. --}}
@php
    $appUpgrade = \App\Support\NativeApp::inApp() ? \App\Support\AppUpgrade::clientConfig() : [];
    $appUpgradeText = [];
    foreach (['ask_title', 'must_title', 'body', 'update', 'later'] as $k) {
        $appUpgradeText[$k] = \App\Support\SiteText::get('app_update.'.$k);
    }
@endphp
@if ($appUpgrade !== [])
    <script src="{{ asset('js/app-update.js') }}?v={{ config('app.version') }}" defer data-navigate-once
            data-config="{{ json_encode($appUpgrade) }}" data-text="{{ json_encode($appUpgradeText) }}"></script>
@endif
