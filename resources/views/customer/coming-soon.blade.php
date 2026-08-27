{{--
    Honest placeholder for a destination whose real screen lands in a later
    phase. Deliberately says plainly that the feature is not available yet
    rather than rendering a non-functional imitation of it — and never
    promises a date, because none is known. Heading and body come from the
    whitelisted map in App\Http\Controllers\Customer\PageController, never
    from the URL, so no user-supplied text is reflected here.
--}}
<x-layouts.customer :title="$heading">
    <div class="mx-auto flex max-w-xl flex-col items-center px-4 py-20 text-center sm:px-6 sm:py-28">
        <span aria-hidden="true" class="grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-slate-500">
            <x-icon name="clock" class="h-7 w-7" />
        </span>

        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $heading }}</h1>
        <p class="mt-3 text-base leading-relaxed text-slate-600">{{ $body }}</p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('customer.home') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                Back to home
            </a>
            <a href="{{ route('customer.help') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                Visit help centre
            </a>
        </div>
    </div>
</x-layouts.customer>
