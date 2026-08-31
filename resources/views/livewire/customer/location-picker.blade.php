<div>
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
            class="inline-flex min-h-11 max-w-[7rem] items-center gap-1.5 rounded-md px-2 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:max-w-[14rem] sm:px-3 lg:max-w-[9rem]">
        <x-icon name="map" class="h-4 w-4 shrink-0 text-slate-500" />
        <span class="truncate">{{ $activeZone?->name ?? 'Set location' }}</span>
    </button>

    @if ($open)
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
                            We're not serving your current location yet. Pick a nearby area below to keep browsing.
                        </p>
                    @endif

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

                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            $wire.useCurrentLocation(
                                position.coords.latitude,
                                position.coords.longitude,
                            );
                        },
                        () => {
                            button.disabled = false;
                            label.textContent = 'Use my current location';
                            error.textContent = "We couldn't get your location. Please choose an area below instead.";
                            error.hidden = false;
                        },
                        { timeout: 10000 },
                    );
                }, { once: true });
            };

            let wasOpen = false;

            const sync = () => {
                const dialog = document.querySelector('[data-location-dialog]');

                if (dialog && ! wasOpen) {
                    lastFocused = document.activeElement;
                    document.addEventListener('keydown', onKeydown);
                    wireGeolocation(dialog);
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
