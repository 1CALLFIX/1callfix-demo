{{-- F3: a city's landing page. Copy comes from the franchise-edited city_page_contents row, if any. --}}
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => 'Home', 'url' => route('customer.home')],
            ['label' => $city->name, 'url' => null],
        ]" />

        <header class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $heading }}</h1>
            @if ($intro)
                <p class="mt-2 max-w-2xl whitespace-pre-line text-sm leading-relaxed text-slate-600">{{ $intro }}</p>
            @endif
        </header>

        <section class="mt-8" aria-label="Categories in {{ $city->name }}">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($categories as $category)
                    <x-customer.category-tile :category="$category" />
                @endforeach
            </div>
        </section>
    </div>
</div>
