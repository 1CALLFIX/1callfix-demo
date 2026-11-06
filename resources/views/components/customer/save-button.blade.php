@props(['item', 'title', 'image' => null, 'hash' => null, 'small' => false, 'url' => null])
@php
    // ♡ Like / Save (owner 2026-11-07, the Flipkart / Meesho / Urban Company pattern): a round button on the photo,
    // beside Share. Signed in: one tap saves to Account → Saved (the heart fills red with a small pop); tap again to
    // remove. A visitor goes to sign-in and comes back to this page.
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $user = auth()->user();
    $path = $here = '/'.ltrim(\Livewire\Livewire::isLivewireRequest() ? (string) \Livewire\Livewire::originalPath() : request()->path(), '/');
    // A card in a list saves the card's own page (`url`), not the list it is shown on.
    if ($url !== null) {
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
    $url = $path.($hash ? '#'.$hash : '');
    $saved = \App\Support\SavedItems::has($user, $item);
    $box = $small ? 'h-8 w-8' : 'h-10 w-10';
    $icon = $small ? 'h-4 w-4' : 'h-5 w-5';
@endphp
@if ($user)
    <button type="button"
            x-data="{
                saved: @js($saved), busy: false, pop: false,
                async toggle() {
                    if (this.busy) return;
                    this.busy = true; this.saved = ! this.saved; this.pop = this.saved;
                    try {
                        const r = await fetch(@js(route('customer.saved.toggle')), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                            body: JSON.stringify({ ticket: @js(\App\Support\SavedItems::ticket($item, $title, $url, $image)) }),
                        });
                        if (! r.ok) throw new Error(r.status);
                        this.saved = (await r.json()).saved;
                    } catch (e) { this.saved = ! this.saved; }
                    this.busy = false; setTimeout(() => this.pop = false, 400);
                },
            }"
            x-on:click.prevent.stop="toggle()"
            :aria-pressed="saved.toString()"
            aria-label="{{ $t('saved.button', ['name' => $title]) }}"
            {{ $attributes->merge(['class' => "inline-flex $box shrink-0 items-center justify-center rounded-full bg-white/95 shadow-md ring-1 ring-black/5 backdrop-blur transition hover:scale-105 ".($saved ? 'text-rose-500' : 'text-slate-700')]) }}
            x-bind:class="saved ? '!text-rose-500' : '!text-slate-700'"
            data-save-button data-saved="{{ $saved ? '1' : '0' }}">
        <svg class="{{ $icon }} transition-transform duration-300" :class="pop ? 'scale-125' : 'scale-100'" viewBox="0 0 24 24" :fill="saved ? 'currentColor' : 'none'" fill="{{ $saved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 4.5 7 4.5c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.6 0 5.5 3.7 4.3 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>
    </button>
@else
    <a href="{{ route('customer.login', ['intended' => $here]) }}"
       aria-label="{{ $t('saved.button', ['name' => $title]) }}"
       {{ $attributes->merge(['class' => "inline-flex $box shrink-0 items-center justify-center rounded-full bg-white/95 text-slate-700 shadow-md ring-1 ring-black/5 backdrop-blur transition hover:scale-105"]) }}
       data-save-button data-saved="0">
        <svg class="{{ $icon }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 4.5 7 4.5c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.6 0 5.5 3.7 4.3 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>
    </a>
@endif
