{{--
    "Explore 1CallFix" doors (owner mockup 2026-10-30): one tile per business live for this visitor (Admin → Modules),
    in the Super Admin's order with their pictures (Admin → Growth → Homepage sections). Words from SiteText.
    Three fit a phone side by side; more scroll sideways, never shrink below a thumb-sized tile.
--}}
@php $platform = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp
<section aria-labelledby="explore-heading" class="mx-auto max-w-7xl px-4 pt-6 pb-2 sm:px-6 lg:px-8" data-testid="home-doors">
    <h2 id="explore-heading" class="text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
        {{ \App\Support\SiteText::get('home.explore_title', ['brand' => $platform]) }}
    </h2>
    @if (\App\Support\HomeDoors::showPromise())
        <p class="mt-1 text-sm text-slate-600">{{ \App\Support\SiteText::get('home.promise') }}</p>
    @endif

    <ul class="mt-4 grid snap-x snap-mandatory grid-flow-col auto-cols-[minmax(6.5rem,1fr)] gap-3 overflow-x-auto pb-2 sm:auto-cols-[minmax(10rem,1fr)] sm:gap-4">
        @foreach ($doors as $door)
            <li class="snap-start" wire:key="door-{{ $door['key'] }}">
                <a href="{{ $door['url'] }}" wire:navigate data-door="{{ $door['key'] }}"
                   class="group flex h-full flex-col items-center rounded-3xl border border-slate-200 bg-white p-3 text-center shadow-sm shadow-slate-900/5 transition duration-200 hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-lg hover:shadow-blue-600/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:p-4">
                    <span class="grid aspect-square w-full max-w-28 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-blue-50 to-sky-50">
                        <img src="{{ $door['image'] }}" alt="" width="112" height="112" loading="eager" decoding="async"
                             @class(["h-full w-full transition duration-300 group-hover:scale-105", \App\Support\SiteControls::imageFit() === "cover" ? "object-cover" : "object-contain"])>
                    </span>
                    <span class="mt-2 text-sm font-bold text-slate-900 sm:text-base">{{ $door['title'] }}</span>
                    <span class="mt-0.5 text-[11px] leading-snug text-slate-500 sm:text-xs">{{ $door['line'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
