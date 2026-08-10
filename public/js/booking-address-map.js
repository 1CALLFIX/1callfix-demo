/**
 * New Booking modal — single-marker "click to place" address picker.
 *
 * Structured exactly like public/js/zone-map.js (per-wrapper state in a
 * WeakMap, a [data-address-map]:not([data-ready]) discovery loop driven by
 * a MutationObserver since the modal is inserted into the DOM long after
 * page load, polling for window.google.maps instead of a callback= param
 * for the same race-condition reason documented at the top of zone-map.js).
 * Kept as a separate plain file rather than inline Livewire @script content
 * for the same reason too — Livewire's SupportMultipleRootElementDetection
 * misparses bare `<`/`</` inside inline <script> blocks as malformed HTML
 * (see Livewire GitHub discussion #7975).
 *
 * $wire isn't in scope here (this isn't a @script block), so writing back
 * to Livewire uses Livewire.find(componentId).set(model, value) — one call
 * for the lat model, one for the lng model.
 */
(function () {
    const NELLORE = { lat: 14.4426, lng: 79.9865 }; // 1CallFix HQ city — same fallback as zone-map.js

    const instances = new WeakMap();

    function mapsReady() {
        return window.google && window.google.maps;
    }

    function initAll() {
        document.querySelectorAll('[data-address-map]:not([data-abm-ready])').forEach((wrapper) => {
            wrapper.setAttribute('data-abm-ready', '1');

            if (mapsReady()) {
                createInstance(wrapper);
                return;
            }

            let waited = 0;
            const timer = setInterval(() => {
                if (mapsReady()) {
                    clearInterval(timer);
                    createInstance(wrapper);
                } else if ((waited += 50) > 15000) {
                    clearInterval(timer);
                    setStatus(wrapper, 'Map failed to load — check the Google Maps API key.');
                }
            }, 50);
        });
    }

    function el(wrapper, role) {
        return wrapper.querySelector('[data-role="' + role + '"]');
    }

    function setStatus(wrapper, text) {
        const node = el(wrapper, 'status');
        if (node) node.textContent = text;
    }

    function createInstance(wrapper) {
        const canvas = el(wrapper, 'canvas');
        if (!canvas) return;

        const centerLat = parseFloat(wrapper.dataset.centerLat);
        const centerLng = parseFloat(wrapper.dataset.centerLng);
        const center = (!isNaN(centerLat) && !isNaN(centerLng)) ? { lat: centerLat, lng: centerLng } : NELLORE;

        const state = {
            wrapper,
            map: new google.maps.Map(canvas, { center, zoom: 14 }),
            marker: null,
            componentId: wrapper.dataset.livewireId || null,
            latModel: wrapper.dataset.latModel || 'newAddressLat',
            lngModel: wrapper.dataset.lngModel || 'newAddressLng',
        };
        instances.set(wrapper, state);

        state.map.addListener('click', (e) => placeMarker(state, e.latLng.lat(), e.latLng.lng()));

        requestAnimationFrame(() => {
            google.maps.event.trigger(state.map, 'resize');
        });
    }

    function placeMarker(state, lat, lng) {
        if (state.marker) {
            state.marker.setPosition({ lat, lng });
        } else {
            state.marker = new google.maps.Marker({ position: { lat, lng }, map: state.map });
        }

        setStatus(state.wrapper, 'Location set — click again to move it.');
        syncToLivewire(state, lat, lng);
    }

    function syncToLivewire(state, lat, lng) {
        const component = (window.Livewire && state.componentId)
            ? window.Livewire.find(state.componentId)
            : null;

        if (!component) return;

        component.set(state.latModel, lat);
        component.set(state.lngModel, lng);
    }

    const observer = new MutationObserver(() => initAll());
    observer.observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('livewire:navigated', initAll);
    initAll();
})();
