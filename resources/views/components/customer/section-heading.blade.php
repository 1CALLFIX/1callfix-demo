@props([
    'id',
    'title',
    'description' => null,
    'link' => null,
    'linkLabel' => 'See all',
])

{{--
    A homepage/section header with an optional "see all" link (Phase C).

    Exists to keep the heading LEVEL consistent: every one of these is an
    <h2> under the page's single <h1>, so the document outline stays valid
    across six screens (WCAG 2.1 AA 1.3.1). Sections pass their own $id and
    reference it with aria-labelledby, which is what makes each rail a
    properly-named landmark rather than an anonymous div.

    The "see all" link's accessible name includes the section title, so a
    screen-reader user listing links on the homepage hears "See all most
    booked services" rather than five identical "See all" entries.
--}}

<div {{ $attributes->merge(['class' => 'flex items-end justify-between gap-4']) }}>
    <div class="min-w-0">
        <h2 id="{{ $id }}" class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm text-slate-600">{{ $description }}</p>
        @endif
    </div>

    @if ($link)
        {{-- A real, prominent action, not muted metadata: every marketplace
             puts a visible "see all" at the end of a rail heading because the
             rail itself only ever shows the first handful of cards. Semibold +
             a chevron affordance; colour still slate pending the accent-palette
             decision. --}}
        <a href="{{ $link }}"
           class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded text-sm font-semibold text-blue-700 transition hover:gap-1.5 hover:text-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
            <span aria-hidden="true">{{ $linkLabel }}</span>
            <span class="sr-only">{{ $linkLabel }} — {{ $title }}</span>
            <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                <path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" />
            </svg>
        </a>
    @endif
</div>
