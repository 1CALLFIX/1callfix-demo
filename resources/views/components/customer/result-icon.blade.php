@props([
    'name',
    'color' => null,
    'imageUrl' => null,
])

{{--
    The small square icon shown next to a search-suggestion row.

    Same construction as x-customer.category-tile's artwork tile — a coloured
    lettered fallback with the real image (a category's own artwork) layered
    on top when there is one — so a suggestion row and the category tile it
    points at read as the same thing. The letter is always rendered, so a
    missing or 404ing image degrades to the initial rather than a broken box.
--}}

<span aria-hidden="true"
      {{ $attributes->merge(['class' => 'relative grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-lg text-sm font-semibold text-slate-700']) }}
      style="background-color: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color) ? $color.'26' : '#E2E8F0' }}">
    <x-customer.initial :name="$name" />

    @if ($imageUrl)
        <img src="{{ $imageUrl }}" alt="" loading="lazy" decoding="async" width="36" height="36"
             class="absolute inset-0 h-full w-full object-cover">
    @endif
</span>
