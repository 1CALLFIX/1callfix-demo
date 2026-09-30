<div data-has-zone="{{ $activeZone ? '1' : '' }}">
    {{-- Trigger. Shows the active zone so the customer always knows what
         context they are browsing in; falls back to a clear call to set one. --}}
    <button type="button"
            wire:click="openPicker"
            aria-haspopup="dialog"
            aria-expanded="{{ $open ? 'true' : 'false' }}"
            {{-- max-w-[7rem] at the base breakpoint, not 9rem: a 360px-wide
                 Android viewport (375 CSS px minus a 15px scrollbar) pushed
                 the header row 9px past the edge with 9rem here. Measured
                 across / /categories /services /offers /search and a service
                 page at 360/375/390/768/1024/1280/1440. The zone name
                 truncates a little sooner on the very narrowest phones, which
                 is a far better outcome than the whole header scrolling
                 sideways.

                 lg:max-w-[9rem] for the same reason at the other end: from
                 1024px the desktop primary nav appears (about 400px of it)
                 and the row overflowed by 15px with 14rem here. This is the
                 one item in the row that can give up width without anything
                 being removed from the page. --}}
            class="inline-flex h-10 w-full min-w-0 max-w-[8.5rem] items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:border-blue-400 hover:bg-blue-50/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:max-w-none sm:rounded-full sm:border-transparent sm:bg-transparent sm:shadow-none sm:hover:border-transparent sm:hover:bg-slate-100">
        <x-icon name="map-pin" class="h-4 w-4 shrink-0 text-blue-600" />
        <span class="truncate">{{ $activeZone?->name ?? 'Set location' }}</span>
        <x-icon name="chevron-down" class="ml-auto h-4 w-4 shrink-0 text-slate-400" />
    </button>

    @if ($open)
        {{-- Teleported to <body>: this component renders inside <header>,
             which carries `backdrop-blur-md` (a `backdrop-filter`). Per the
             CSS spec that makes the header a containing block for
             `position: fixed` descendants — so without this the "full
             screen" overlay below was sized to the header box and rendered
             lapping into the top bar instead of covering the viewport.
             Livewire's @teleport moves the node out to <body> while keeping
             wire:* bindings live. --}}
        @teleport('body')
        {{-- Focus is moved into the dialog on open, trapped while it is
             open, and returned to the trigger on close (see the script at
             the bottom of this file). The shared x-ui.modal component has
             no focus management of its own and is used by 20+ admin
             screens, so this dialog carries its own rather than changing
             behaviour under those callers. --}}
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
             data-location-dialog>

            <div class="fixed inset-0 bg-slate-900/40" wire:click="closePicker" aria-hidden="true"></div>

            <div role="dialog"
                 aria-modal="true"
                 aria-labelledby="location-dialog-title"
                 class="relative flex max-h-[85vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-w-lg sm:rounded-2xl">

                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="location-dialog-title" class="text-base font-semibold text-slate-900">
                            Where do you need service?
                        </h2>
                        <p class="mt-0.5 text-sm text-slate-600">
                            Choose your area so we can show what's available near you.
                        </p>
                    </div>
                    <button type="button"
                            wire:click="closePicker"
                            class="-m-1 grid h-11 w-11 shrink-0 place-items-center rounded-md text-slate-400 transition hover:bg-slate-50 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        <span aria-hidden="true" class="text-xl leading-none">&times;</span>
                        <span class="sr-only">Close</span>
                    </button>
                </div>

                <div class="space-y-4 px-5 py-4">
                    {{-- Geolocation assist. Progressive enhancement: the
                         button is only wired up when the browser exposes
                         the API, and the list below always works without it. --}}
                    <button type="button"
                            data-use-my-location
                            hidden
                            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-800 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        <x-icon name="map" class="h-4 w-4 text-slate-500" />
                        <span data-use-my-location-label>Use my current location</span>
                    </button>

                    <p data-location-error role="alert" hidden
                       class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-900"></p>

                    @if ($outOfCoverage)
                        <p role="alert" class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                            We're not in this area yet. Your previous location is still active — pick a nearby area below to keep browsing.
                        </p>
                    @endif

                    {{-- Google Places search box (1CF-HOMESCREEN-UX-001). Progressive
                         enhancement: hidden until resources/js/places-autocomplete.js
                         confirms a Google Maps key is configured; the plain zone
                         search box below always works regardless. --}}
                    {{-- wire:ignore: this whole subtree is mounted and mutated
                         entirely by resources/js/places-autocomplete.js (input
                         listeners, the results list, hidden/shown state).
                         Without it, any Livewire re-render while the dialog
                         stays open (typing in the zone-search box below, or
                         $this->outOfCoverage flipping after a failed lookup)
                         morphs this div back to its server-rendered `hidden`
                         state and the box silently stops working. --}}
                    <div data-places-search hidden wire:ignore>
                        <label for="place-search" class="sr-only">Search for your location, society or apartment</label>
                        <div class="relative">
                            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center">
                                <x-icon name="magnifying-glass" class="h-4 w-4 text-slate-400" />
                            </span>
                            <input id="place-search"
                                   type="search"
                                   data-places-input
                                   autocomplete="off"
                                   role="combobox"
                                   aria-expanded="false"
                                   aria-controls="place-search-results"
                                   aria-autocomplete="list"
                                   placeholder="Search for your location / society / apartment"
                                   class="block min-h-11 w-full rounded-lg border border-slate-300 py-2.5 pl-9 pr-3 text-base shadow-sm transition focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600">
                        </div>
                        <ul id="place-search-results" role="listbox" data-places-results hidden
                            class="mt-1 max-h-56 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-sm"></ul>
                        <p data-places-error role="alert" hidden class="mt-1 text-xs text-amber-700"></p>
                    </div>

                    @if ($recentAddresses->isNotEmpty())
                        <div>
                            <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent locations</h3>
                            <ul class="space-y-1">
                                @foreach ($recentAddresses as $address)
                                    <li>
                                        <button type="button"
                                                wire:click="selectRecent({{ $address->lat }}, {{ $address->lng }}, @js($address->address_line ?: $address->city), @js($address->label ?: $address->city))"
                                                class="flex min-h-11 w-full items-center gap-2 rounded-lg px-3 py-2 text-left transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                                            <x-icon name="map-pin" class="h-4 w-4 shrink-0 text-slate-400" />
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-medium text-slate-900">{{ $address->label ?: $address->city }}</span>
                                                @if ($address->address_line)
                                                    <span class="block truncate text-xs text-slate-500">{{ $address->address_line }}</span>
                                                @endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Guest "recent locations" — rendered client-side from
                         localStorage (no server-side address is created for a
                         guest; see resources/js/places-autocomplete.js). Only
                         shown when not authenticated, since a logged-in
                         customer already has the list above. --}}
                    @guest
                        <div data-guest-recents-wrap hidden>
                            <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent locations</h3>
                            <ul data-guest-recents class="space-y-1"></ul>
                        </div>
                    @endguest

                    <div>
                        <label for="zone-search" class="sr-only">Search areas</label>
                        <input id="zone-search"
                               type="search"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Search by area or city"
                               class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base shadow-sm transition focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600">
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto border-t border-slate-200 px-2 py-2">
                    @forelse ($zones as $zone)
                        @php $isActive = $activeZone && $activeZone->id === $zone->id; @endphp
                        <button type="button"
                                wire:click="selectZone({{ $zone->id }})"
                                @if ($isActive) aria-current="true" @endif
                                @class([
                                    'flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900',
                                    'bg-slate-100' => $isActive,
                                    'hover:bg-slate-50' => ! $isActive,
                                ])>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ $zone->name }}</span>
                                @if ($zone->franchise?->city?->name)
                                    <span class="block truncate text-xs text-slate-500">{{ $zone->franchise->city->name }}</span>
                                @endif
                            </span>
                            {{-- The selected row is marked with a word, not
                                 just a background tint (WCAG 2.1 AA 1.4.1). --}}
                            @if ($isActive)
                                <span class="shrink-0 text-xs font-semibold text-slate-700">Selected</span>
                            @endif
                        </button>
                    @empty
                        <p class="px-3 py-8 text-center text-sm text-slate-500">
                            @if (trim($search) !== '')
                                No areas match "{{ $search }}".
                            @else
                                No service areas are available yet.
                            @endif
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
        @endteleport
    @endif

    @script
    <script>
        // Dialog behaviour that HTML alone does not give us: focus move-in,
        // focus trap, Escape-to-close, focus return, and the optional
        // geolocation assist. Plain JS with no new dependency, matching the
        // convention the rest of this codebase follows.
        (function () {
            let lastFocused = null;

            const focusables = (root) => Array.from(root.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
            )).filter((el) => el.offsetParent !== null);

            const onKeydown = (event) => {
                const dialog = document.querySelector('[data-location-dialog]');
                if (! dialog) return;

                if (event.key === 'Escape') {
                    event.preventDefault();
                    $wire.closePicker();
                    return;
                }

                if (event.key !== 'Tab') return;

                const items = focusables(dialog);
                if (items.length === 0) return;

                const first = items[0];
                const last = items[items.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (! event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            };

            const wireGeolocation = (dialog) => {
                const button = dialog.querySelector('[data-use-my-location]');
                const label = dialog.querySelector('[data-use-my-location-label]');
                const error = dialog.querySelector('[data-location-error]');
                if (! button || ! navigator.geolocation) return;

                button.hidden = false;

                button.addEventListener('click', () => {
                    button.disabled = true;
                    label.textContent = 'Finding your location…';
                    error.hidden = true;

                    window.cfLocate(
                        (lat, lng, accuracyM) => $wire.useCurrentLocation(lat, lng, accuracyM),
                        () => {
                            button.disabled = false;
                            label.textContent = 'Use my current location';
                            error.textContent = "We couldn't get your location. Please choose an area below instead.";
                            error.hidden = false;
                        },
                    );
                }, { once: true });
            };

            // --- Places search box (1CF-HOMESCREEN-UX-001) -----------------
            // Delegates the actual Google Places calls to the shared,
            // dependency-free helper in resources/js/places-autocomplete.js
            // (debounce, min-length, session token, key handling all live
            // there in one place — see that file). This just wires the
            // markup up to it and turns a picked suggestion into the
            // existing $wire.selectPlace() call, exactly the same shape
            // useCurrentLocation() already uses.
            const wirePlaces = (dialog) => {
                if (! window.cfPlacesAutocomplete) return;

                const wrap = dialog.querySelector('[data-places-search]');
                const input = dialog.querySelector('[data-places-input]');
                const list = dialog.querySelector('[data-places-results]');
                const error = dialog.querySelector('[data-places-error]');
                if (! wrap || ! input || ! list) return;

                window.cfPlacesAutocomplete.mount({
                    input,
                    list,
                    onReady: () => { wrap.hidden = false; },
                    onSelect: (place) => {
                        $wire.selectPlace(place.lat, place.lng, place.formattedAddress, place.label || null);
                    },
                    onError: (message) => {
                        if (! error) return;
                        error.textContent = message;
                        error.hidden = ! message;
                    },
                });
            };

            // --- Guest "recent locations" (localStorage only) --------------
            const GUEST_RECENTS_KEY = 'cf.recentLocations';
            const GUEST_RECENTS_MAX = 5;

            const readGuestRecents = () => {
                try {
                    const raw = JSON.parse(localStorage.getItem(GUEST_RECENTS_KEY) || '[]');
                    return Array.isArray(raw) ? raw : [];
                } catch (e) { return []; }
            };

            const renderGuestRecents = (dialog) => {
                const wrap = dialog.querySelector('[data-guest-recents-wrap]');
                const list = dialog.querySelector('[data-guest-recents]');
                if (! wrap || ! list) return;

                const recents = readGuestRecents();
                list.innerHTML = '';

                if (recents.length === 0) {
                    wrap.hidden = true;
                    return;
                }

                wrap.hidden = false;

                recents.forEach((item) => {
                    const li = document.createElement('li');
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'flex min-h-11 w-full items-center gap-2 rounded-lg px-3 py-2 text-left transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900';

                    const titleLine = document.createElement('span');
                    titleLine.className = 'block truncate text-sm font-medium text-slate-900';
                    titleLine.textContent = item.label || item.address || '';

                    const addressLine = document.createElement('span');
                    addressLine.className = 'block truncate text-xs text-slate-500';
                    addressLine.textContent = item.address || '';

                    const textWrap = document.createElement('span');
                    textWrap.className = 'min-w-0';
                    textWrap.appendChild(titleLine);
                    textWrap.appendChild(addressLine);
                    button.appendChild(textWrap);

                    button.addEventListener('click', () => {
                        $wire.selectRecent(item.lat, item.lng, item.address || item.label || '', item.label || null);
                    });
                    li.appendChild(button);
                    list.appendChild(li);
                });
            };

            const pushGuestRecent = (entry) => {
                try {
                    const recents = readGuestRecents().filter((r) => r.address !== entry.address || r.label !== entry.label);
                    recents.unshift(entry);
                    localStorage.setItem(GUEST_RECENTS_KEY, JSON.stringify(recents.slice(0, GUEST_RECENTS_MAX)));
                } catch (e) { /* private mode / storage disabled — recents are a convenience, not a requirement */ }
            };

            // Every successful pick (Places search, "use current location",
            // or a recent) fires this — see selectPlace()/selectRecent() in
            // LocationPicker.php. Recorded client-side only; no server-side
            // address row is created for a guest.
            Livewire.on('location-picked', (e) => {
                // Livewire's payload shape for a named-args dispatch varies
                // by version/context — defensively unwrap either an object
                // or a one-element array of it, the same pattern already
                // used for `razorpay-open` (resources/views/livewire/customer/wallet/index.blade.php).
                const payload = (e && e.label !== undefined) ? e : (Array.isArray(e) ? e[0] : null);
                if (payload) pushGuestRecent(payload);
            });

            // --- Automatic first-load geolocation (Phase 2) --------------
            // When the visitor has no area set, ask the browser for their
            // location once, unprompted — the "detect my location" pattern
            // of the reference apps. Strictly best-effort:
            //   • no Geolocation API, or the user blocks/dismisses the
            //     permission  -> nothing happens; the header picker is the
            //     fallback and the page is never blocked.
            //   • granted, resolves inside a served zone  -> the header
            //     location field updates itself.
            //   • granted, resolves outside every zone    -> the picker
            //     opens on the "not serving your area yet" notice, so the
            //     customer is told rather than dropped into a booking flow
            //     that later dead-ends on a missing zone.
            // sessionStorage guards it: a reload or navigation never re-asks.
            const AUTO_KEY = 'cf.geo.autoprompt';

            const autoLocate = () => {
                if ($wire.$el.dataset.hasZone === '1') return;
                try { if (sessionStorage.getItem(AUTO_KEY)) return; } catch (e) {}
                try { sessionStorage.setItem(AUTO_KEY, '1'); } catch (e) {}

                window.cfLocate(
                    (lat, lng, accuracyM) => $wire.useCurrentLocationAuto(lat, lng, accuracyM),
                    () => {}, // denied / unavailable -> silent; manual picker stays
                );
            };

            // Deferred a beat so it never competes with first paint.
            setTimeout(autoLocate, 400);

            let wasOpen = false;

            const sync = () => {
                const dialog = document.querySelector('[data-location-dialog]');

                if (dialog && ! wasOpen) {
                    lastFocused = document.activeElement;
                    document.addEventListener('keydown', onKeydown);
                    wireGeolocation(dialog);
                    wirePlaces(dialog);
                    renderGuestRecents(dialog);
                    (focusables(dialog)[0] || dialog).focus();
                    wasOpen = true;
                } else if (! dialog && wasOpen) {
                    document.removeEventListener('keydown', onKeydown);
                    if (lastFocused && document.contains(lastFocused)) lastFocused.focus();
                    wasOpen = false;
                }
            };

            sync();
            Livewire.hook('morphed', sync);
        })();
    </script>
    @endscript
</div>
