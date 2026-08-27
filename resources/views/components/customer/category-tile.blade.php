@props([
    'category',
    'count' => null,
])

{{--
    A category shortcut tile (Phase C) — the homepage's discovery rail and
    the category explorer's grid both use this, so the two can never drift
    into different-looking representations of the same row.

    Image, colour and name all come from the `service_categories` row.
    `image_url` already resolves both storage shapes the column carries (a
    public-disk path for admin uploads, a full URL for imported rows), so
    nothing here has to know which kind it got.

    $count is the number of ACTIVE services inside — passed only where the
    caller has already loaded it (withCount), never fetched here, so a grid
    of tiles cannot become a per-tile query. A null count renders no count
    line at all: a category with nothing published yet reads better silent
    than as "0 services", which looks like an error.
--}}

<a href="{{ route('customer.categories.show', $category) }}"
   {{ $attributes->merge(['class' => 'group flex h-full min-h-28 flex-col justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900']) }}>
    {{-- The lettered placeholder is always rendered, and the image (when
         there is one) sits on top of it. That is deliberate rather than an
         if/else: a stored image path that 404s — a moved file, a missing
         `storage:link`, a CDN outage — would otherwise leave an empty box on
         the customer's homepage. This way the letter is simply revealed, with
         no JavaScript and no onerror handler. --}}
    <span aria-hidden="true"
          class="relative grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-lg text-base font-semibold text-slate-700"
          style="background-color: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $category->color) ? $category->color.'26' : '#E2E8F0' }}">
        <x-customer.initial :name="$category->name" />

        @if ($category->image_url)
            <img src="{{ $category->image_url }}" alt="" loading="lazy" decoding="async"
                 width="48" height="48"
                 class="absolute inset-0 h-full w-full object-cover">
        @endif
    </span>

    <span class="flex flex-col gap-0.5">
        <span class="text-sm font-semibold text-slate-900">{{ $category->name }}</span>
        @if ($count)
            <span class="text-xs text-slate-500">{{ $count }} {{ \Illuminate\Support\Str::plural('service', $count) }}</span>
        @endif
    </span>
</a>
