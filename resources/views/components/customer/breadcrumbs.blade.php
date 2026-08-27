@props(['items'])

{{--
    Breadcrumb trail (Phase C).

    $items is an ordered array of ['label' => string, 'url' => ?string]. The
    LAST entry is the current page and is rendered as plain text with
    aria-current="page" rather than as a link to itself — a link that goes
    nowhere is a worse answer than no link.

    <nav aria-label="Breadcrumb"> wrapping an <ol> is the pattern assistive
    technology actually recognises; the separators are aria-hidden so the
    trail is announced as "Home, AC Repair, AC General Service" and not
    "Home slash AC Repair slash".
--}}

@php
    $items = collect($items)->filter()->values();
    $last = $items->count() - 1;
@endphp

@if ($items->isNotEmpty())
    <nav aria-label="Breadcrumb" {{ $attributes->merge(['class' => 'text-sm']) }}>
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-slate-500">
            @foreach ($items as $index => $item)
                <li class="flex items-center gap-1.5">
                    @if ($index > 0)
                        <span aria-hidden="true" class="text-slate-300">/</span>
                    @endif

                    @if ($index === $last || empty($item['url']))
                        <span @if ($index === $last) aria-current="page" @endif class="font-medium text-slate-900">
                            {{ $item['label'] }}
                        </span>
                    @else
                        <a href="{{ $item['url'] }}"
                           class="rounded underline-offset-4 hover:text-slate-900 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                            {{ $item['label'] }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
