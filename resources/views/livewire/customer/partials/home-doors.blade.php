{{--
    "Explore 1CallFix" doors (owner mockup 2026-10-30): one tile per business live for this visitor (Admin → Modules),
    in the Super Admin's order with their pictures (Admin → Growth → Homepage sections). Words from SiteText.
    Four fit a phone side by side (a fifth peeks in); more scroll sideways.
--}}
@php $platform = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp
<section aria-labelledby="explore-heading" class="mx-auto max-w-7xl px-4 pt-6 pb-2 sm:px-6 lg:px-8" data-testid="home-doors">
    <h2 id="explore-heading" class="text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
        {{ \App\Support\SiteText::get('home.explore_title', ['brand' => $platform]) }}
    </h2>
    @if (\App\Support\HomeDoors::showPromise())
        <p class="mt-1 text-sm text-slate-600">{{ \App\Support\SiteText::get('home.promise') }}</p>
    @endif

    {{-- Owner 2026-11-05: four fit a phone; a fifth peeks in at the edge so people see the row scrolls. --}}
    <ul class="mt-3 grid snap-x snap-mandatory grid-flow-col auto-cols-[minmax(calc((100%_-_1.5rem)/4.4),1fr)] gap-2 overflow-x-auto pb-2 sm:auto-cols-[minmax(9rem,1fr)] sm:gap-4">
        @foreach ($doors as $door)
            <li class="snap-start" wire:key="door-{{ $door['key'] }}">
                <a href="{{ $door['url'] }}" wire:navigate data-door="{{ $door['key'] }}" style="{{ \App\Support\ModuleTheme::style($door['key']) ?: \App\Support\ModuleTheme::style('services') }}"
                   class="group relative flex h-full flex-col items-center overflow-hidden rounded-2xl border border-slate-200 bg-white p-1.5 text-center shadow-sm shadow-slate-900/5 transition duration-200 hover:-translate-y-0.5 hover:border-[var(--mod)] hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:rounded-3xl sm:p-4">
                    {{-- Each business in its own colour (2026-11-07): tinted picture box and a bar along the bottom. --}}
                    <span class="grid aspect-square w-full max-w-16 place-items-center overflow-hidden rounded-xl bg-[var(--mod-soft)] sm:max-w-28 sm:rounded-2xl">
                        <img src="{{ $door['image'] }}" alt="" width="112" height="112" loading="eager" decoding="async"
                             @class(["h-full w-full transition duration-300 group-hover:scale-105", \App\Support\SiteControls::imageFit() === "cover" ? "object-cover" : "object-contain"])>
                    </span>
                    <span class="mt-1.5 text-xs font-bold text-slate-900 sm:mt-2 sm:text-base">{{ $door['title'] }}</span>
                    <span class="mt-0.5 line-clamp-2 text-[10px] leading-tight text-slate-500 sm:text-xs">{{ $door['line'] }}</span>
                    <span class="absolute inset-x-0 bottom-0 h-1 bg-[var(--mod)]" aria-hidden="true"></span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
