/**
 * Zones admin screen — boundary maps.
 *
 * Lives here as a plain static file, NOT inside the Livewire component's
 * @script block. Livewire's SupportMultipleRootElementDetection parses the
 * component's rendered HTML with PHP's DOMDocument to verify a single root
 * element, and it misparses bare `<`/`</` characters inside inline <script>
 * content (e.g. comparisons like `points.length < 3`) as malformed HTML tags
 * — see Livewire GitHub discussion #7975. Keeping the JS in a normal external
 * file sidesteps that parser entirely.
 *
 * Because this isn't inside a @script block, the `$wire` magic variable isn't
 * available — we use `Livewire.find(componentId)` instead, reading the id off
 * each wrapper's data-livewire-id attribute.
 *
 * MULTI-INSTANCE. The Zones screen is one page carrying two maps: the "Add
 * New Zone" form pinned at the top, and the edit modal, which Livewire only
 * inserts into the DOM when a row's pencil is clicked. The previous version
 * of this file was a hard singleton (one module-level `map`, one `#zone-map`
 * element, global button ids) and could not have driven both. Each map is now
 * an independent instance discovered from a `[data-zone-map]` wrapper, with
 * its controls scoped inside that wrapper via data-role attributes.
 *
 * Every wrapper must carry wire:ignore. Google builds a large DOM tree inside
 * the canvas, and Livewire's morphing would otherwise tear it out on any
 * re-render (typing in a `.live` field is enough), leaving a blank grey box.
 *
 * NOTE: google.maps.drawing.DrawingManager was removed by Google as of Maps
 * JS API v3.65 (https://developers.google.com/maps/deprecations). Boundaries
 * are drawn manually — click to place points — instead.
 */
(function () {
    const NELLORE = { lat: 14.4426, lng: 79.9865 }; // 1CallFix HQ city
    const STYLE = {
        fillColor: '#4f46e5',
        fillOpacity: 0.15,
        strokeColor: '#4338ca',
        strokeWeight: 2,
    };

    // Per-wrapper state. A WeakMap so a closed modal's instance is collected
    // with its DOM node — no manual teardown, no leak across open/close cycles.
    const instances = new WeakMap();

    function mapsReady() {
        return window.google && window.google.maps;
    }

    /** Find every wrapper on the page that hasn't been wired up yet. */
    function initAll() {
        document.querySelectorAll('[data-zone-map]:not([data-zm-ready])').forEach((wrapper) => {
            wrapper.setAttribute('data-zm-ready', '1');

            if (mapsReady()) {
                createInstance(wrapper);
                return;
            }

            // The Maps script is loaded async in the layout with no callback=
            // param on purpose: callback= requires the global to exist the
            // instant the script finishes, which races our own init. Poll
            // instead, and give up rather than spin forever if the key is bad.
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

    function setFinishDisabled(wrapper, disabled) {
        const btn = el(wrapper, 'finish');
        if (btn) btn.disabled = disabled;
    }

    function createInstance(wrapper) {
        const canvas = el(wrapper, 'canvas');
        if (!canvas) return;

        const state = {
            wrapper,
            map: new google.maps.Map(canvas, { center: NELLORE, zoom: 12 }),
            shape: null,
            points: [],
            drawing: false,
            componentId: wrapper.dataset.livewireId || null,
            model: wrapper.dataset.model || 'boundaryPolygonJson',
        };
        instances.set(wrapper, state);

        state.map.addListener('click', (e) => {
            if (!state.drawing) return;
            state.points.push({ lat: e.latLng.lat(), lng: e.latLng.lng() });
            renderProgress(state);
        });

        const on = (role, fn) => {
            const btn = el(wrapper, role);
            if (btn) btn.addEventListener('click', fn);
        };
        on('start', () => startDrawing(state));
        on('finish', () => finishDrawing(state));
        on('clear', () => clearShape(state));

        // An existing boundary arrives as a data attribute rendered by Blade,
        // rather than a Livewire event. The modal's markup is only created at
        // the moment it opens, so the data is already in the DOM by the time
        // this runs — no event/DOM race to lose.
        let existing = [];
        try {
            existing = JSON.parse(wrapper.dataset.polygon || '[]');
        } catch (e) {
            existing = [];
        }
        drawExisting(state, existing);

        // A map created inside a just-opened modal can measure its container
        // before the browser has laid it out, leaving a grey strip. Nudge it
        // once on the next frame.
        requestAnimationFrame(() => {
            google.maps.event.trigger(state.map, 'resize');
            if (state.shape) fitToShape(state);
        });
    }

    function startDrawing(state) {
        if (state.shape) state.shape.setMap(null);
        state.shape = null;
        state.points = [];
        state.drawing = true;
        setFinishDisabled(state.wrapper, true);
        setStatus(state.wrapper, 'Click on the map to add points…');
    }

    function renderProgress(state) {
        if (state.shape) state.shape.setMap(null);
        state.shape = new google.maps.Polygon(
            Object.assign({}, STYLE, { paths: state.points, editable: false, map: state.map })
        );
        setFinishDisabled(state.wrapper, state.points.length < 3);
        setStatus(
            state.wrapper,
            state.points.length + ' point' + (state.points.length === 1 ? '' : 's') +
            ' placed — need at least 3.'
        );
    }

    function finishDrawing(state) {
        if (state.points.length < 3) return;
        state.drawing = false;
        if (state.shape) state.shape.setMap(null);
        state.shape = new google.maps.Polygon(
            Object.assign({}, STYLE, { paths: state.points, editable: true, map: state.map })
        );
        bindPathListeners(state);
        syncToLivewire(state);
        setFinishDisabled(state.wrapper, true);
        setStatus(state.wrapper, 'Boundary set — drag the points to fine-tune, or Start Drawing to redo.');
    }

    function clearShape(state) {
        if (state.shape) state.shape.setMap(null);
        state.shape = null;
        state.points = [];
        state.drawing = false;
        setFinishDisabled(state.wrapper, true);
        setLivewireValue(state, '[]');
        setStatus(state.wrapper, 'Cleared.');
    }

    function bindPathListeners(state) {
        ['insert_at', 'remove_at', 'set_at'].forEach((evt) => {
            google.maps.event.addListener(state.shape.getPath(), evt, () => syncToLivewire(state));
        });
    }

    function fitToShape(state) {
        const bounds = new google.maps.LatLngBounds();
        state.shape.getPath().getArray().forEach((p) => bounds.extend(p));
        state.map.fitBounds(bounds);
    }

    function drawExisting(state, points) {
        if (!Array.isArray(points) || points.length < 3) return;
        if (state.shape) state.shape.setMap(null);

        // zones.boundary_polygon stores [{lat, lng}, …] — already the shape
        // google.maps.Polygon paths expect, no transform needed.
        state.shape = new google.maps.Polygon(
            Object.assign({}, STYLE, { paths: points, editable: true, map: state.map })
        );
        state.points = points.slice();
        fitToShape(state);
        bindPathListeners(state);
        syncToLivewire(state);
        setStatus(state.wrapper, 'Existing boundary loaded — drag points to adjust, or Start Drawing to replace it.');
    }

    function syncToLivewire(state) {
        if (!state.shape) return;
        const path = state.shape.getPath().getArray().map((p) => ({ lat: p.lat(), lng: p.lng() }));
        setLivewireValue(state, JSON.stringify(path));
    }

    function setLivewireValue(state, json) {
        const component = (window.Livewire && state.componentId)
            ? window.Livewire.find(state.componentId)
            : null;

        if (component) component.set(state.model, json);
    }

    // The edit modal is inserted long after page load, so watch for it rather
    // than relying on an event fired at a moment we might not be listening.
    // initAll() is guarded by data-zm-ready, so repeat calls are free.
    const observer = new MutationObserver(() => initAll());
    observer.observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('livewire:navigated', initAll);
    initAll();
})();
