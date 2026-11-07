@php
    use App\Support\InfoPages\ContactDetails as C;
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $tel = C::telHref();
    $wa = C::whatsappHref();
    $email = C::get('email');
    $hours = C::get('hours');
    $company = C::get('company');
    $address = C::get('address');
    $map = C::get('map_url');
    $grievance = array_values(array_filter(preg_split('/\R/', (string) \App\Support\MarketplaceSettings::get('marketplace.grievance_officer'))));
    $social = collect(\App\Models\SocialMediaLink::footerLinks());
    $schema = array_filter([
        '@context' => 'https://schema.org', '@type' => 'Organization',
        'name' => $company ?: \App\Services\Seo\SeoSettings::siteName(), 'url' => url('/'),
        'telephone' => C::get('phone'), 'email' => $email,
        'address' => $address ? ['@type' => 'PostalAddress', 'streetAddress' => preg_replace('/\R/', ', ', $address)] : null,
    ]);
@endphp
{{-- Contact page (owner 2026-11-07). Details: Admin → Website & Pages → Contact page (Super Admin only). Words: Website text → Contact page. --}}
<x-layouts.customer :title="$t('contact.title')" :meta-description="$t('contact.intro')" :indexable="true" :schema="[$schema]">
    <div class="mb-bottom-nav">
        <section class="bg-gradient-to-b from-blue-50 to-white">
            <div class="mx-auto max-w-5xl px-4 pb-6 pt-10 sm:px-6 sm:pt-14 lg:px-8">
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{{ $t('contact.title') }}</h1>
                <p class="mt-3 max-w-2xl text-base text-slate-600">{{ $t('contact.intro') }}</p>
                <x-customer.share-button :title="$t('contact.title')" class="mt-4" />
            </div>
        </section>

        <div class="mx-auto max-w-5xl px-4 pb-14 sm:px-6 lg:px-8">
            @if (C::hasAny())
                <ul class="grid gap-3 sm:grid-cols-3" data-contact-channels>
                    @if ($tel)
                        <li><a href="{{ $tel }}" class="cf-card flex h-full items-center gap-3 p-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-700"><x-icon name="phone" class="h-6 w-6" /></span>
                            <span class="min-w-0"><span class="block text-sm font-semibold text-slate-900">{{ $t('contact.call') }}</span><span class="block truncate text-sm text-slate-600">{{ C::get('phone') }}</span></span>
                        </a></li>
                    @endif
                    @if ($wa)
                        <li><a href="{{ $wa }}" target="_blank" rel="noopener" class="cf-card flex h-full items-center gap-3 p-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700"><x-icon name="chat" class="h-6 w-6" /></span>
                            <span class="min-w-0"><span class="block text-sm font-semibold text-slate-900">{{ $t('contact.whatsapp') }}</span><span class="block truncate text-sm text-slate-600">{{ C::get('whatsapp') }}</span></span>
                        </a></li>
                    @endif
                    @if ($email)
                        <li><a href="mailto:{{ $email }}" class="cf-card flex h-full items-center gap-3 p-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-50 text-amber-700"><x-icon name="document-text" class="h-6 w-6" /></span>
                            <span class="min-w-0"><span class="block text-sm font-semibold text-slate-900">{{ $t('contact.email') }}</span><span class="block truncate text-sm text-slate-600">{{ $email }}</span></span>
                        </a></li>
                    @endif
                </ul>
                @if ($hours)
                    <p class="mt-3 inline-flex items-center gap-2 text-sm text-slate-600"><x-icon name="clock" class="h-4 w-4 text-slate-400" /> <span class="font-medium text-slate-800">{{ $t('contact.hours') }}:</span> {{ $hours }}</p>
                @endif
            @else
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-sm text-slate-600" data-contact-soon>{{ $t('contact.soon') }}</p>
            @endif

            <div class="mt-8 grid gap-4 md:grid-cols-3">
                <div class="cf-card p-5">
                    <h2 class="font-semibold text-slate-900">{{ $t('contact.help_title') }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $t('contact.help_text') }}</p>
                    <a href="{{ route('customer.help') }}" class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">{{ $t('contact.help_button') }}</a>
                </div>
                <div class="cf-card p-5">
                    <h2 class="font-semibold text-slate-900">{{ $t('contact.orders_title') }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $t('contact.orders_text') }}</p>
                    <a href="{{ route('customer.orders.index') }}" class="mt-3 inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-800 hover:border-blue-400">{{ $t('contact.orders_button') }}</a>
                </div>
                <div class="cf-card p-5">
                    <h2 class="font-semibold text-slate-900">{{ $t('contact.partner_title') }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $t('contact.partner_text') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('customer.partners') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-800 hover:border-blue-400">{{ $t('contact.partner_button') }}</a>
                        @if (\App\Support\InfoPages\FranchisePage::enabled())
                            <a href="{{ route('customer.franchise') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-800 hover:border-blue-400">{{ $t('contact.franchise_button') }}</a>
                        @endif
                    </div>
                </div>
            </div>

            @if ($company || $address || $grievance || $social->isNotEmpty())
                <div class="mt-8 grid gap-4 md:grid-cols-2">
                    @if ($company || $address)
                        <div class="rounded-2xl bg-slate-50 p-5">
                            <h2 class="font-semibold text-slate-900">{{ $t('contact.office') }}</h2>
                            @if ($company)<p class="mt-2 text-sm font-medium text-slate-800">{{ $company }}</p>@endif
                            @if ($address)<p class="mt-1 whitespace-pre-line text-sm text-slate-600" data-contact-address>{{ $address }}</p>@endif
                            @if ($map)<a href="{{ $map }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-blue-700 underline-offset-4 hover:underline"><x-icon name="map-pin" class="h-4 w-4" /> {{ $t('contact.map') }}</a>@endif
                        </div>
                    @endif
                    @if ($grievance)
                        <div class="rounded-2xl bg-slate-50 p-5">
                            <h2 class="font-semibold text-slate-900">{{ $t('contact.grievance') }}</h2>
                            @foreach ($grievance as $line)<p class="mt-1 text-sm text-slate-600">{{ $line }}</p>@endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($social->isNotEmpty())
                <ul class="mt-6 flex flex-wrap gap-2">
                    @foreach ($social as $s)
                        <li><a href="{{ $s['url'] }}" target="_blank" rel="noopener me" class="grid h-11 w-11 place-items-center rounded-full border border-slate-300 text-slate-500 hover:border-blue-400 hover:text-blue-700">
                            <span class="sr-only">{{ $s['label'] }}</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{{ $s['path'] }}" /></svg>
                        </a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts.customer>
