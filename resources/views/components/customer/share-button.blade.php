@props(['title', 'hash' => null, 'compact' => false])
@php
    // Owner 2026-11-06: share a service, product, shop or restaurant. Inside the Android apps the phone's own share
    // sheet (Capacitor Share plugin, from the app build of 2026-11-06); in a phone browser the browser's share sheet;
    // otherwise (computers, older app builds) a small menu with WhatsApp and Copy link. The link is this page without
    // its query string, so the city and slug stay and no tracking or search state is passed on.
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $message = $t('share.message', ['name' => $title]);
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
    @if ($compact)
        <button type="button" x-on:click="share()" :aria-expanded="open.toString()" aria-label="{{ $t('share.button_item', ['name' => $title]) }}"
                class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-800">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
        </button>
    @else
        <button type="button" x-on:click="share()" :aria-expanded="open.toString()"
                class="inline-flex min-h-10 items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
            {{ $t('share.button') }}
        </button>
    @endif
    <div x-show="open" x-cloak x-transition.opacity
         @class(['absolute z-30 mt-2 w-48 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-lg', 'right-0' => $compact, 'left-0' => ! $compact])>
        <a :href="whatsapp()" target="_blank" rel="noopener" class="flex min-h-11 items-center px-4 font-medium text-slate-800 hover:bg-slate-50" data-share-whatsapp>{{ $t('share.whatsapp') }}</a>
        <button type="button" x-on:click="copy()" class="flex min-h-11 w-full items-center px-4 text-left font-medium text-slate-800 hover:bg-slate-50" data-share-copy>
            <span x-show="!copied">{{ $t('share.copy') }}</span>
            <span x-show="copied" x-cloak class="text-green-700">{{ $t('share.copied') }}</span>
        </button>
    </div>
</div>
