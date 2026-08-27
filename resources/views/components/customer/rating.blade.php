@props([
    'rating' => null,
    'count' => null,
    'showCount' => true,
])

{{--
    A service's star rating (Phase C).

    Renders NOTHING when $rating is null. That is the whole point of this
    component existing: a service nobody has reviewed and a service reviewed
    badly must not look alike, so an unrated service gets no star row rather
    than an empty or zeroed one. ServiceRatingSummary returns null (never
    0.0) for an unrated service precisely so this check is possible.

    The figure is a real average over `reviews` joined through `bookings` —
    see App\Services\Customer\ServiceRatingSummary for why that join is the
    only honest per-service rating this schema can produce.

    One filled star plus the number, rather than five partially-filled ones:
    the number is the information, and five glyphs at card size are decoration
    that screen readers then have to be told to ignore. The accessible name
    states the rating in words.
--}}

@if ($rating !== null)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-sm']) }}>
        <span class="sr-only">Rated {{ $rating }} out of 5{{ $showCount && $count ? ' from '.$count.' '.\Illuminate\Support\Str::plural('review', $count) : '' }}</span>
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-amber-500">
            <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9 4.8 17.6l1-5.8L1.5 7.7l5.9-.9L10 1.5z" />
        </svg>
        <span aria-hidden="true" class="font-semibold text-slate-900">{{ number_format($rating, 1) }}</span>
        @if ($showCount && $count)
            <span aria-hidden="true" class="text-slate-500">({{ $count }})</span>
        @endif
    </span>
@endif
