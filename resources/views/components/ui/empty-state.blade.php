@props([
    'icon' => 'clipboard',
    'title' => 'Nothing here yet',
    'description' => null,
])

{{--
    Admin Polish + AI session. Standardizes the "No X yet." single gray
    <td>/<p> line every list screen wrote by hand, in as many slightly
    different phrasings — used on the screens this session actually
    touched (Bookings, Providers, Dashboard's panels); not retrofitted onto
    every other screen platform-wide, since that would be a much larger,
    unreviewed sweep outside this session's stated scope. Renders as a
    standalone block (e.g. inside an <x-ui.table> row's single <td>, or in
    place of a card's content) rather than owning the <tr>/<td> itself,
    since row/colspan shape is caller-specific.
--}}

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 py-10 text-center']) }}>
    <span class="w-10 h-10 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center">
        <x-icon :name="$icon" class="w-5 h-5" />
    </span>
    <p class="text-sm font-medium text-gray-500">{{ $title }}</p>
    @if ($description)
        <p class="text-xs text-gray-400 max-w-xs">{{ $description }}</p>
    @endif
</div>
