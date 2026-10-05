{{-- F3: shown only when several cities are live and the visitor has not picked one. noindex (default) on purpose. --}}
<x-layouts.customer title="Choose your city">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header>
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Choose your city</h1>
            <p class="mt-3 text-base text-slate-600">Prices and available professionals depend on where you are.</p>
        </header>

        <ul class="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($cities as $city)
                <li>
                    <a href="{{ $city['url'] }}"
                       class="block rounded-xl border border-slate-200 bg-white p-4 text-base font-semibold text-slate-900 transition hover:border-blue-300 hover:shadow-md">
                        {{ $city['name'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.customer>
