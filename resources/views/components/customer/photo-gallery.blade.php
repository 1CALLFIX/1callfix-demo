{{-- Every photo of an item can be seen (owner 2026-11-14): swipe or arrows on the big photo, a "2 / 7" counter, all
     photos as tiles below, and a full-screen view. ♡ Like + Share on the top-right corner. Used by halls and homes. --}}
@props(['photos' => [], 'title' => '', 'item' => '', 'cover' => null])
    <div {{ $attributes }} x-data="{ i: 0, n: {{ max(1, count($photos)) }}, open: false,
            go(k) { this.i = (k + this.n) % this.n; this.$refs.strip?.children[this.i]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' }); } }"
         @keydown.escape.window="open = false" data-testid="photo-gallery">
        <div class="relative">
            <ul x-ref="strip" class="flex snap-x snap-mandatory overflow-x-auto rounded-2xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="{{ \App\Support\SiteText::get('hall.show.photos') }}"
                @scroll.debounce.100ms="i = Math.round($el.scrollLeft / $el.clientWidth)">
                @forelse ($photos as $idx => $p)
                    <li class="w-full shrink-0 snap-start">
                        <button type="button" class="block w-full" @click="i = {{ $idx }}; open = true">
                            <img src="{{ $p }}" alt="{{ $title }} {{ $idx + 1 }}" class="aspect-[3/2] w-full rounded-2xl object-cover sm:aspect-[16/7]" @if ($idx > 0) loading="lazy" @endif>
                        </button>
                    </li>
                @empty
                    <li class="aspect-[3/2] w-full rounded-2xl bg-slate-100"></li>
                @endforelse
            </ul>
            <x-customer.item-actions class="absolute right-3 top-3" :item="$item" :title="$title" :image="$cover" />
            @if (count($photos) > 1)
                <button type="button" @click="go(i - 1)" class="absolute left-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-2xl shadow" aria-label="Previous photo">‹</button>
                <button type="button" @click="go(i + 1)" class="absolute right-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-2xl shadow" aria-label="Next photo">›</button>
                <span class="absolute bottom-3 left-3 rounded-full bg-black/60 px-3 py-1 text-xs font-semibold text-white"><span x-text="i + 1">1</span> / {{ count($photos) }}</span>
                <button type="button" @click="open = true" class="absolute bottom-3 right-3 min-h-11 rounded-full bg-white/90 px-4 text-sm font-semibold shadow">{{ \App\Support\SiteText::get('hall.show.all_photos', ['n' => count($photos)]) }}</button>
            @endif
        </div>
        @if (count($photos) > 1)
            <ul class="mt-2 grid grid-cols-5 gap-2 sm:grid-cols-8" data-testid="photo-thumbs">
                @foreach ($photos as $idx => $p)
                    <li><button type="button" @click="go({{ $idx }})" :class="i === {{ $idx }} ? 'ring-2 ring-blue-600 ring-offset-2' : 'opacity-80 hover:opacity-100'" class="block w-full overflow-hidden rounded-lg">
                        <img src="{{ $p }}" alt="" loading="lazy" class="aspect-square w-full object-cover"></button></li>
                @endforeach
            </ul>
        @endif
        @if ($photos !== [])
            <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto bg-black/90 p-4" role="dialog" aria-modal="true" aria-label="{{ \App\Support\SiteText::get('hall.show.photos') }}">
                <div class="mx-auto max-w-4xl">
                    <div class="sticky top-0 flex justify-end py-2"><button type="button" @click="open = false" class="min-h-11 rounded-full bg-white px-5 font-semibold">{{ \App\Support\SiteText::get('hall.show.close') }} ✕</button></div>
                    <ul class="space-y-3">
                        @foreach ($photos as $idx => $p)
                            <li><img src="{{ $p }}" alt="{{ $title }} {{ $idx + 1 }}" loading="lazy" class="w-full rounded-xl"></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
