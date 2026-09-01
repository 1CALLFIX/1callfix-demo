/**
 * Shared browser-geolocation helper.
 *
 * One wrapper over navigator.geolocation.getCurrentPosition so the option
 * set — and any later refinement of it — lives in exactly one place. Used by:
 *   - the header location picker (auto-detect on load + the in-dialog button)
 *   - the "Use my current location" button in the booking wizard's inline
 *     add-address form and the saved-addresses page
 *
 * Stays dependency-free and tiny, matching the rest of resources/js.
 *
 *   window.cfLocate(onSuccess, onError, opts)
 *     onSuccess(latitude, longitude, accuracyMetres)
 *     onError(kind)   kind ∈ 'unsupported' | 'denied' | 'unavailable' | 'timeout' | 'error'
 *     opts.highAccuracy  default false  (the address forms pass true)
 *     opts.timeout       default 10000
 *     opts.maximumAge    default 600000 (accept a ≤10-min-old fix, fewer prompts)
 */
window.cfLocate = function (onSuccess, onError, opts) {
    opts = opts || {};
    const fail = (kind) => { if (typeof onError === 'function') onError(kind); };

    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
        fail('unsupported');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (pos) => onSuccess(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy),
        (err) => fail(({ 1: 'denied', 2: 'unavailable', 3: 'timeout' })[err && err.code] || 'error'),
        {
            enableHighAccuracy: !!opts.highAccuracy,
            timeout: opts.timeout || 10000,
            maximumAge: opts.maximumAge != null ? opts.maximumAge : 600000,
        },
    );
};

/**
 * Wires a `[data-locate-address]` button (add-address forms in the booking
 * wizard and the saved-addresses page) to the given Livewire $wire. On a
 * fix it calls $wire.useCurrentLocationForNewAddress(lat, lng); a blocked
 * or failed permission just restores the button and the form still works
 * by hand. Re-binds after each Livewire morph, once per button.
 */
window.cfWireLocateButton = function (wire) {
    const bind = () => {
        const btn = document.querySelector('[data-locate-address]');
        if (!btn || btn.dataset.bound) return;
        btn.dataset.bound = '1';

        const label = btn.querySelector('[data-locate-address-label]');
        const reset = () => { btn.disabled = false; if (label) label.textContent = 'Use my current location'; };

        btn.addEventListener('click', () => {
            btn.disabled = true;
            if (label) label.textContent = 'Locating…';
            window.cfLocate(
                (lat, lng) => Promise.resolve(wire.useCurrentLocationForNewAddress(lat, lng)).finally(reset),
                reset,
                { highAccuracy: true },
            );
        });
    };

    bind();
    if (window.Livewire) window.Livewire.hook('morphed', bind);
};
