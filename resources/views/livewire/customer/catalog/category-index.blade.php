{{-- The full category explorer (Phase C). See the CategoryIndex component's docblock. --}}

<div class="mb-bottom-nav">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-customer.breadcrumbs :items="[
            ['label' => 'Home', 'url' => route('customer.home')],
            ['label' => 'All categories', 'url' => null],
        ]" />

        <header class="mt-4">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">All categories</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                Every service category available. Pick one to see its subcategories and services.
            </p>
        </header>

        @if ($categories->isNotEmpty())
            <ul class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($categories as $category)
                    {{-- flex column, not a bare <li>: the tile carried `h-full`
                         and the subcategory line below it then added height the
                         grid row had not reserved, so that line was drawn
                         underneath the next row of tiles. Making the item a
                         column and letting the TILE flex to fill what is left
                         puts both inside the cell. Caught visually in browser
                         testing. --}}
                    <li class="flex flex-col">
                        <x-customer.category-tile :category="$category" :count="$category->services_count" class="flex-1" />

                        @if ($category->subcategories->isNotEmpty())
                            {{-- The subcategories are shown as text under the
                                 tile rather than as more links: they are here
                                 so a customer can tell what a category
                                 contains before clicking, and eight more tab
                                 stops per tile would make the grid painful to
                                 navigate by keyboard. --}}
                            <p class="mt-2 line-clamp-2 px-1 text-xs leading-relaxed text-slate-500">
                                {{ $category->subcategories->pluck('name')->take(4)->implode(' · ') }}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <div class="mt-8 rounded-xl border border-slate-200">
                <x-ui.empty-state title="No categories published yet"
                                  description="Service categories will appear here once they are published from the admin panel." />
            </div>
        @endif

        @if ($banners->isNotEmpty())
            <div class="mt-12">
                <x-customer.banner-carousel :banners="$banners"
                                            id="category-index-banners"
                                            label="Offers and announcements"
                                            variant="strip" />
            </div>
        @endif
    </div>
</div>
