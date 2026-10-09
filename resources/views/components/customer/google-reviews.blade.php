{{--
    Google Business reviews (Admin → System → Google reviews). Real reviews exactly as Google returned them: nothing
    is written or edited here, and the whole section renders NOTHING until there is fresh data — no placeholder, no
    invented rating. Review text is escaped; links go only to Google addresses (validated when fetched).
--}}
@php
    $google = app(\App\Services\Reviews\GoogleReviews::class)->display();
@endphp

@if ($google)
    <section aria-labelledby="google-reviews-heading" class="border-y border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="google-reviews-heading" class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        What customers say on Google
                    </h2>
                    @if ($google['rating'])
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-slate-600">
                            <span class="text-lg font-semibold text-slate-900">{{ number_format($google['rating'], 1) }}</span>
                            <span class="text-amber-500" role="img" aria-label="{{ number_format($google['rating'], 1) }} out of 5 stars">{{ str_repeat('★', (int) round($google['rating'])) }}<span class="text-slate-300">{{ str_repeat('★', 5 - (int) round($google['rating'])) }}</span></span>
                            @if ($google['count'] > 0)
                                <span>from {{ number_format($google['count']) }} Google reviews</span>
                            @endif
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    @if ($google['url'])
                        <a href="{{ $google['url'] }}" target="_blank" rel="noopener nofollow"
                           class="font-semibold text-blue-700 underline-offset-2 hover:underline">See all reviews on Google</a>
                    @endif
                    @if ($google['write_url'])
                        <a href="{{ $google['write_url'] }}" target="_blank" rel="noopener nofollow"
                           class="rounded-lg bg-blue-600 px-3 py-2 font-semibold text-white hover:bg-blue-700">Write a review</a>
                    @endif
                </div>
            </div>

            <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($google['reviews'] as $review)
                    <li class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            @if ($review['photo'])
                                <img src="{{ $review['photo'] }}" alt="" width="40" height="40" loading="lazy" decoding="async"
                                     referrerpolicy="no-referrer" class="h-10 w-10 rounded-full object-cover">
                            @else
                                <span class="grid h-10 w-10 place-items-center rounded-full bg-blue-50 text-sm font-semibold text-blue-700" aria-hidden="true">{{ mb_strtoupper(mb_substr($review['author'], 0, 1)) }}</span>
                            @endif
                            <div class="min-w-0">
                                @if ($review['author_url'])
                                    <a href="{{ $review['author_url'] }}" target="_blank" rel="noopener nofollow" class="block truncate text-sm font-semibold text-slate-900 hover:underline">{{ $review['author'] }}</a>
                                @else
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ $review['author'] }}</p>
                                @endif
                                @if ($review['when'])
                                    <p class="text-xs text-slate-500">{{ $review['when'] }}</p>
                                @endif
                            </div>
                        </div>
                        <p class="mt-3 text-amber-500" role="img" aria-label="{{ $review['rating'] }} out of 5 stars">{{ str_repeat('★', $review['rating']) }}<span class="text-slate-300">{{ str_repeat('★', 5 - $review['rating']) }}</span></p>
                        <p class="mt-2 line-clamp-6 text-sm leading-relaxed text-slate-700">{{ $review['text'] }}</p>
                    </li>
                @endforeach
            </ul>

            <p class="mt-6 text-xs text-slate-500">Reviews from Google. Powered by Google.</p>
        </div>
    </section>
@endif
