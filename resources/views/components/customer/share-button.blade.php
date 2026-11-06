@props(['title', 'hash' => null, 'compact' => false, 'round' => false, 'small' => false])
@php
    // Owner 2026-11-06: share a service, product, shop or restaurant. Inside the Android apps the phone's own share
    // sheet (Capacitor Share plugin, from the app build of 2026-11-06); in a phone browser the browser's share sheet;
    // otherwise (computers, older app builds) a small menu with WhatsApp and Copy link. The link is this page without
    // its query string, so the city and slug stay and no tracking or search state is passed on.
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $message = $t('share.message', ['name' => $title]);
    // 2026-11-07 (owner's Flipkart / Meesho / Urban Company screenshots): `round` is the white circle that sits on a
    // photo's top-right corner beside the ♡ (x-customer.save-button); `small` makes it fit a dish photo.
    $icon = $small ? 'h-4 w-4' : 'h-5 w-5';
@endphp
<div x-data="{
        open: false,
        copied: false,
        link() { return window.location.origin + window.location.pathname + @js($hash ? '#'.$hash : ''); },
        async share() {
            const data = { title: @js($title), text: @js($message), url: this.link() };
            const cap = window.Capacitor;
            if (cap && typeof cap.isPluginAvailable === 'function' && cap.isPluginAvailable('Share')) {
                try { await cap.Plugins.Share.share({ ...data, dialogTitle: @js($t('share.button')) }); return; }
                catch (e) { if (/cancel/i.test(String(e && (e.message || e)))) return; }
            }
            if (navigator.share) {
                try { await navigator.share(data); return; }
                catch (e) { if (e && e.name === 'AbortError') return; }
            }
            this.open = !this.open;
        },
        whatsapp() { return 'https://wa.me/?text=' + encodeURIComponent(@js($message) + ' ' + this.link()); },
        async copy() {
            const url = this.link();
            try { await navigator.clipboard.writeText(url); }
            catch (e) {
                const box = document.createElement('textarea');
                box.value = url; document.body.appendChild(box); box.select();
                try { document.execCommand('copy'); } catch (err) {}
                box.remove();
            }
            this.copied = true;
            setTimeout(() => { this.copied = false; this.open = false; }, 1500);
        },
    }"
    x-on:click.outside="open = false" x-on:keydown.escape="open = false"
    {{ $attributes->merge(['class' => 'relative inline-block']) }} data-share-button>
    @if ($round)
        <button type="button" x-on:click.prevent.stop="share()" :aria-expanded="open.toString()" aria-label="{{ $t('share.button_item', ['name' => $title]) }}"
                @class(['inline-flex shrink-0 items-center justify-center rounded-full bg-white/95 text-blue-700 shadow-md ring-1 ring-black/5 backdrop-blur transition hover:scale-105', 'h-8 w-8' => $small, 'h-10 w-10' => ! $small])>
            <svg class="{{ $icon }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 3 10.5 13.5"/><path d="M21 3l-6.5 18-4-7.5L3 9.5 21 3z"/></svg>
        </button>
    @elseif ($compact)
        <button type="button" x-on:click="share()" :aria-expanded="open.toString()" aria-label="{{ $t('share.button_item', ['name' => $title]) }}"
                class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-800">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 3 10.5 13.5"/><path d="M21 3l-6.5 18-4-7.5L3 9.5 21 3z"/></svg>
        </button>
    @else
        <button type="button" x-on:click="share()" :aria-expanded="open.toString()"
                class="inline-flex min-h-10 items-center gap-2 rounded-full bg-blue-50 px-4 text-sm font-semibold text-blue-700 ring-1 ring-blue-100 transition hover:bg-blue-100">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 3 10.5 13.5"/><path d="M21 3l-6.5 18-4-7.5L3 9.5 21 3z"/></svg>
            {{ $t('share.button') }}
        </button>
    @endif
    <div x-show="open" x-cloak x-transition.opacity
         @class(['absolute z-30 mt-2 w-48 text-left overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-lg', 'right-0' => $compact || $round, 'left-0' => ! $compact && ! $round])>
        <a :href="whatsapp()" target="_blank" rel="noopener" class="flex min-h-11 items-center px-4 font-medium text-slate-800 hover:bg-slate-50" data-share-whatsapp>{{ $t('share.whatsapp') }}</a>
        <button type="button" x-on:click="copy()" class="flex min-h-11 w-full items-center px-4 text-left font-medium text-slate-800 hover:bg-slate-50" data-share-copy>
            <span x-show="!copied">{{ $t('share.copy') }}</span>
            <span x-show="copied" x-cloak class="text-green-700">{{ $t('share.copied') }}</span>
        </button>
    </div>
</div>
