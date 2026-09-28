/**
 * Google Places (New) Autocomplete + Place Details, for the customer web
 * location picker's search box (1CF-HOMESCREEN-UX-001).
 *
 * There was no Google Places integration anywhere in this codebase before
 * this file — see docs/PHASE_HOMESCREEN_UX_IMPLEMENTATION.md part 3. The
 * only prior Google integration is the admin panel's Maps JavaScript API
 * (drawing zone boundaries), a different product with a different billing
 * model. This reuses the SAME browser key (`window.CF_GOOGLE_PLACES.key`,
 * echoed from `config('services.google_maps.key')` — see
 * components/layouts/customer.blade.php) rather than adding a second key or
 * a second config path.
 *
 * Deliberately calls the Places REST endpoints directly with `fetch`
 * (`places.googleapis.com/v1/places:autocomplete` and `:GET places/{id}`)
 * rather than loading the `maps.googleapis.com/maps/api/js?libraries=places`
 * JS SDK: this file is on the customer homepage's critical path and the repo
 * is deliberately dependency-free (see resources/js/app.js's own docblock),
 * so a second ~40kb+ script tag for a picker most visits never open is not
 * worth it. The field mask on both calls keeps the response (and therefore
 * the response tier billed) to only what this file actually reads.
 *
 * ── Good-practice rules this file follows (§7 of the spec) ────────────────
 *  - Never call Google for fewer than 3 characters.
 *  - Debounces input by ~300ms.
 *  - Uses ONE Places session token per "search session" (new box focus ->
 *    a place is picked or the box is abandoned), the mechanism Google's own
 *    billing docs describe as the way an autocomplete session + the one
 *    Place Details call that follows it are billed together rather than
 *    per-keystroke. A fresh token is drawn after every completed pick.
 *  - Never logs a full address; only a short devtools breadcrumb with the
 *    place id, never the text a customer typed or the resolved address.
 */
(function () {
    const DEBOUNCE_MS = 300;
    const MIN_LENGTH = 3;
    const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    function newSessionToken() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        // Fallback for a browser with no crypto.randomUUID — still unique
        // enough for a client-side session-token string, never security-sensitive.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function debounce(fn, ms) {
        let timer = null;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    }

    /**
     * Mounts the widget onto one {input, list} pair. Returns nothing; all
     * interaction happens through the onSelect/onReady/onError callbacks
     * the caller supplies (see location-picker.blade.php).
     */
    function mount({ input, list, onReady, onSelect, onError }) {
        const config = window.CF_GOOGLE_PLACES;
        if (! config || ! config.key) {
            // No key configured — the caller's markup stays hidden and the
            // plain zone-name search box is the only location search.
            return;
        }

        let sessionToken = newSessionToken();
        let activeIndex = -1;
        let currentPredictions = [];

        const clearList = () => {
            list.innerHTML = '';
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
            currentPredictions = [];
        };

        const renderPredictions = (predictions) => {
            currentPredictions = predictions;
            list.innerHTML = '';

            if (predictions.length === 0) {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                return;
            }

            predictions.forEach((prediction, index) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.id = 'place-option-' + index;
                li.className = 'cursor-pointer px-3 py-2.5 text-sm hover:bg-slate-50';
                const main = prediction.structuredFormat?.mainText?.text || prediction.text?.text || '';
                const secondary = prediction.structuredFormat?.secondaryText?.text || '';
                li.innerHTML = '<span class="block truncate font-medium text-slate-900"></span>'
                    + (secondary ? '<span class="block truncate text-xs text-slate-500"></span>' : '');
                const spans = li.querySelectorAll('span');
                spans[0].textContent = main;
                if (spans[1]) spans[1].textContent = secondary;

                li.addEventListener('mousedown', (event) => {
                    // mousedown (not click) so it fires before the input's blur.
                    event.preventDefault();
                    choosePrediction(prediction);
                });

                list.appendChild(li);
            });

            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        const choosePrediction = async (prediction) => {
            const placeId = prediction.placePrediction?.placeId;
            if (! placeId) return;

            try {
                const response = await fetch(DETAILS_URL + placeId, {
                    method: 'GET',
                    headers: {
                        'X-Goog-Api-Key': config.key,
                        // Field mask keeps the response — and the Places
                        // "Essentials"-tier billing it triggers — to only
                        // what this picker actually uses.
                        'X-Goog-FieldMask': 'location,formattedAddress,shortFormattedAddress,displayName',
                        'X-Goog-Session-Token': sessionToken,
                    },
                });

                if (! response.ok) {
                    throw new Error('places-details-http-' + response.status);
                }

                const place = await response.json();
                const lat = place.location?.latitude;
                const lng = place.location?.longitude;

                if (typeof lat !== 'number' || typeof lng !== 'number') {
                    throw new Error('places-details-no-location');
                }

                input.value = place.shortFormattedAddress || place.displayName?.text || place.formattedAddress || '';
                clearList();
                // A completed pick closes this "session" for billing
                // purposes — the next keystroke starts a fresh one.
                sessionToken = newSessionToken();

                onSelect({
                    lat,
                    lng,
                    formattedAddress: place.formattedAddress || place.shortFormattedAddress || input.value,
                    label: place.displayName?.text || null,
                });
            } catch (error) {
                // Never log the address text itself — only that a lookup failed.
                console.warn('[places-autocomplete] details lookup failed', placeId);
                onError && onError("We couldn't look up that place. Please try again or pick an area below.");
            }
        };

        const fetchPredictions = async (input_text) => {
            try {
                const response = await fetch(AUTOCOMPLETE_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Goog-Api-Key': config.key,
                        'X-Goog-FieldMask': 'suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat',
                    },
                    body: JSON.stringify(Object.assign(
                        {
                            input: input_text,
                            sessionToken,
                        },
                        config.regionCode ? { includedRegionCodes: [config.regionCode] } : {},
                    )),
                });

                if (! response.ok) {
                    throw new Error('places-autocomplete-http-' + response.status);
                }

                const data = await response.json();
                const predictions = (data.suggestions || []).filter((s) => s.placePrediction);
                renderPredictions(predictions);
                onError && onError(null);
            } catch (error) {
                console.warn('[places-autocomplete] autocomplete lookup failed');
                onError && onError("We couldn't search locations right now. Please pick an area below instead.");
                clearList();
            }
        };

        const debouncedFetch = debounce((value) => {
            const term = value.trim();
            if (term.length < MIN_LENGTH) {
                clearList();
                return;
            }
            fetchPredictions(term);
        }, DEBOUNCE_MS);

        input.addEventListener('input', (event) => debouncedFetch(event.target.value));

        input.addEventListener('keydown', (event) => {
            if (list.hidden || currentPredictions.length === 0) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(activeIndex + 1, currentPredictions.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                choosePrediction(currentPredictions[activeIndex]);
                return;
            } else if (event.key === 'Escape') {
                clearList();
                return;
            } else {
                return;
            }

            Array.from(list.children).forEach((li, index) => {
                li.classList.toggle('bg-slate-100', index === activeIndex);
            });
            input.setAttribute('aria-activedescendant', 'place-option-' + activeIndex);
        });

        input.addEventListener('blur', () => {
            // Small delay so a mousedown-selected option still registers
            // before the list is torn down.
            setTimeout(clearList, 150);
        });

        onReady && onReady();
    }

    window.cfPlacesAutocomplete = { mount };
})();
