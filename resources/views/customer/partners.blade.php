{{--
    Public "For professionals" landing page (route customer.partners,
    URL /coming-soon/partners). The benefits grid is driven entirely by the
    admin-managed `partner_benefits` table — nothing in it is hardcoded here.
    The rest is static copy describing the real /provider/register flow
    (phone OTP -> details -> documents -> admin review). Single CTA phrase
    throughout: "Join as a Partner", always linking to /provider/register.

    Visual language matches customer/how-it-works.blade.php: the customer
    layout, blue-600 accent, slate text, numbered circular step markers,
    slate-50 bordered cards.
--}}
@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $cityLabel = \App\Models\Setting::get('branding.operating_city_label', null);

    $ctaLabel = 'Join as a Partner';
    $registerUrl = route('provider.register');

    // Mirrors App\Livewire\Provider\Auth\Register's step machine
    // (phone | verify_phone | details) plus the admin review that follows.
    $steps = [
        [
            'title' => 'Verify your phone',
            'body' => 'Enter your mobile number and confirm the one-time code. That number is your login.',
        ],
        [
            'title' => 'Tell us about your work',
            'body' => 'Add your name, a password, the area you cover and the trades you handle.',
        ],
        [
            'title' => 'Upload your documents',
            'body' => 'Submit the ID and proof documents we ask for. Uploads are checked before you can take jobs.',
        ],
        [
            'title' => 'Get approved and go online',
            'body' => 'Our team reviews your profile. Once approved, sign in, go online and start accepting job offers.',
        ],
    ];
@endphp

<x-layouts.customer title="{{ $ctaLabel }}"
    metaDescription="Partner with {{ $platformName }}: steady local jobs for verified trade professionals{{ $cityLabel ? ' in '.$cityLabel : '' }}. Set your own hours, get paid on time.">

    {{-- Hero --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-4xl px-4 py-16 text-center sm:px-6 sm:py-20 lg:px-8">
            <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">For professionals</p>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                Grow your trade business with {{ $platformName }}
            </h1>
            <p class="mx-auto mt-4 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg">
                Verified local professionals get matched with real jobs{{ $cityLabel ? ' across '.$cityLabel : '' }} —
                work the hours you choose, on prices set up front, with payouts you can track.
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ $registerUrl }}"
                   class="inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    {{ $ctaLabel }}
                </a>
                <a href="#how-it-works"
                   class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    See how it works
                </a>
            </div>
        </div>
    </section>

    {{-- Benefits grid — admin-managed, hidden entirely if there are no active rows --}}
    @if ($benefits->isNotEmpty())
        <section aria-labelledby="benefits-heading" class="mx-auto max-w-5xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
            <h2 id="benefits-heading" class="text-center text-2xl font-bold tracking-tight text-slate-900">
                Why partner with {{ $platformName }}
            </h2>
            <div class="mt-10 grid gap-6 sm:grid-cols-2">
                @foreach ($benefits as $benefit)
                    <div class="flex gap-4 rounded-xl border border-slate-200 bg-slate-50 p-6">
                        <span aria-hidden="true"
                              class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-blue-600 text-white">
                            <x-icon :name="$benefit->icon" class="h-5 w-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">{{ $benefit->title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $benefit->description }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- How it works — the real registration flow --}}
    <section id="how-it-works" aria-labelledby="how-heading" class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-4xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
            <h2 id="how-heading" class="text-center text-2xl font-bold tracking-tight text-slate-900">
                How to get started
            </h2>
            <ol class="mt-10 grid gap-8 sm:grid-cols-2">
                @foreach ($steps as $index => $step)
                    <li class="flex gap-4">
                        <span aria-hidden="true"
                              class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-blue-600 text-sm font-bold text-white">
                            {{ $index + 1 }}
                        </span>
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">
                                <span class="sr-only">Step {{ $index + 1 }}: </span>{{ $step['title'] }}
                            </h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $step['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="mx-auto max-w-4xl px-4 py-16 text-center sm:px-6 sm:py-20 lg:px-8">
        <h2 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
            Ready to take on more work?
        </h2>
        <p class="mx-auto mt-3 max-w-xl text-base text-slate-600">
            Registration takes a few minutes. You can take jobs as soon as your profile is approved.
        </p>
        <div class="mt-8">
            <a href="{{ $registerUrl }}"
               class="inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                {{ $ctaLabel }}
            </a>
        </div>
        <p class="mt-4 text-sm text-slate-500">
            Already a partner?
            <a href="{{ route('provider.login') }}"
               class="font-medium text-slate-900 underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 rounded">Sign in</a>
        </p>
    </section>

</x-layouts.customer>
