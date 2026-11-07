{{--
    "Get the app" strip on phones (owner 2026-11-07; Super Admin → Homepage & sign-up). Sits in the page flow above the
    header, so it never covers the bottom menu or the pay bar. Hidden until resources/js/app-banner.js decides: a phone
    browser only (never a computer, never inside our Android app, never once the website is installed), Android → Play
    Store, iPhone/iPad → "Add to Home Screen" steps (there is no iOS app). Closing it hides it on that phone for the
    Super Admin's number of days.
--}}
@if (\App\Support\SiteControls::appBanner() && ! \App\Support\NativeApp::inApp())
    @php $t = fn (string $key) => \App\Support\SiteText::get($key); @endphp
    <div hidden data-app-banner data-hide-days="{{ \App\Support\SiteControls::appBannerDays() }}"
         class="border-b border-slate-200 bg-slate-50 md:hidden" role="region" aria-label="{{ $t('app_banner.title') }}">
        <div class="flex items-center gap-3 px-4 py-2">
            <img src="{{ asset('icons/icon.svg') }}" alt="" class="h-9 w-9 shrink-0 rounded-lg" width="36" height="36">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900">{{ $t('app_banner.title') }}</p>
                <p class="text-xs text-slate-600" data-app-banner-android hidden>{{ $t('app_banner.android_line') }}</p>
                <p class="text-xs text-slate-600" data-app-banner-ios hidden>{{ $t('app_banner.ios_line') }}</p>
            </div>
            <a href="https://play.google.com/store/apps/details?id={{ \App\Support\NativeApp::PACKAGES['customer'] }}"
               data-app-banner-android hidden target="_blank" rel="noopener"
               class="inline-flex min-h-9 shrink-0 items-center rounded-full bg-blue-600 px-3 text-sm font-semibold text-white">
                {{ $t('app_banner.android_button') }}
            </a>
            <button type="button" data-app-banner-close aria-label="{{ $t('app_banner.close') }}"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 hover:bg-slate-200">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
            </button>
        </div>
    </div>
@endif
