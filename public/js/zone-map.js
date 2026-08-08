/**
 * Zones admin screen — boundary map.
 *
 * Lives here as a plain static file, NOT inside the Livewire component's
 * @script block. Livewire's SupportMultipleRootElementDetection parses the
 * component's rendered HTML with PHP's DOMDocument to verify a single root
 * element, and it misparses bare `<`/`</` characters inside inline <script>
 * content (e.g. comparisons like `drawPoints.length < 3`) as malformed HTML
 * tags — see Livewire GitHub discussion #7975. Moving the JS out to a normal
 * external file sidesteps that parser entirely.
 *
 * Because this isn't inside a @script block, the `$wire` magic variable
 * isn't available — we use `Livewire.find(componentId)` instead, reading the
 * component's id off a data-livewire-id attribute (see zones/form.blade.php).
 *
 * NOTE: google.maps.drawing.DrawingManager was removed by Google as of Maps
 * JS API v3.65 (https://developers.google.com/maps/deprecations). This draws
 * boundaries manually — click-to-place-points — instead.
 */
(function () {
    let map, activeShape, isDrawing = false, drawPoints = [];
    let livewireComponent = null;

    function boot() {
        const mapEl = document.getElementById('zone-map');
        if (!mapEl) return; // Zones create/edit screen isn't the current page

        const componentId = mapEl.dataset.livewireId;
        livewireComponent = (window.Livewire && componentId) ? window.Livewire.find(componentId) : null;

        if (window.google && window.google.maps) {
            initZoneMap(mapEl);
        } else {
            const waitForMaps = setInterval(() => {
                if (window.google && window.google.maps) {
                    clearInterval(waitForMaps);
                    initZoneMap(mapEl);
                }
            }, 50);
        }
    }

    function initZoneMap(mapEl) {
        if (map) return; // already initialized once per page load

        map = new google.maps.Map(mapEl, {
            center: { lat: 14.4426, lng: 79.9865 }, // Nellore — 1CallFix HQ city
            zoom: 12,
        });

        map.addListener('click', (e) => {
            if (!isDrawing) return;
            drawPoints.push({ lat: e.latLng.lat(), lng: e.latLng.lng() });
            renderDrawProgress();
        });

        const startBtn = document.getElementById('zone-draw-start');
        const finishBtn = document.getElementById('zone-draw-finish');
        const clearBtn = document.getElementById('zone-draw-clear');
        if (startBtn) startBtn.addEventListener('click', startDrawing);
        if (finishBtn) finishBtn.addEventListener('click', finishDrawing);
        if (clearBtn) clearBtn.addEventListener('click', clearShape);

        // Load existing boundary if editing
        const existing = JSON.parse(mapEl.dataset.polygon || '[]');
        drawExistingBoundary(existing);
    }

    function setStatus(text) {
        const el = document.getElementById('zone-draw-status');
        if (el) el.textContent = text;
    }

    function setFinishDisabled(disabled) {
        const btn = document.getElementById('zone-draw-finish');
        if (btn) btn.disabled = disabled;
    }

    function startDrawing() {
        if (activeShape) activeShape.setMap(null);
        drawPoints = [];
        isDrawing = true;
        setFinishDisabled(true);
        setStatus('Click on the map to add points...');
    }

    function renderDrawProgress() {
        if (activeShape) activeShape.setMap(null);
        activeShape = new google.maps.Polygon({
            paths: drawPoints,
            fillColor: '#4f46e5',
            fillOpacity: 0.15,
            strokeColor: '#4338ca',
            strokeWeight: 2,
            editable: false, // not editable mid-draw — only after Finish
            map: map,
        });
        setFinishDisabled(drawPoints.length < 3);
        setStatus(drawPoints.length + ' point' + (drawPoints.length === 1 ? '' : 's') + ' placed — need at least 3.');
    }

    function finishDrawing() {
        if (drawPoints.length < 3) return;
        isDrawing = false;
        if (activeShape) activeShape.setMap(null);
        activeShape = new google.maps.Polygon({
            paths: drawPoints,
            fillColor: '#4f46e5',
            fillOpacity: 0.15,
            strokeColor: '#4338ca',
            strokeWeight: 2,
            editable: true, // now draggable to fine-tune
            map: map,
        });
        bindPathListeners();
        syncBoundaryToLivewire();
        setFinishDisabled(true);
        setStatus('Boundary set — drag the points to fine-tune, or Start Drawing to redo.');
    }

    function clearShape() {
        if (activeShape) activeShape.setMap(null);
        activeShape = null;
        drawPoints = [];
        isDrawing = false;
        setFinishDisabled(true);
        setLivewireBoundary('[]');
        setStatus('Cleared.');
    }

    function bindPathListeners() {
        ['insert_at', 'remove_at', 'set_at'].forEach((evt) => {
            google.maps.event.addListener(activeShape.getPath(), evt, syncBoundaryToLivewire);
        });
    }

    function drawExistingBoundary(points) {
        if (!Array.isArray(points) || points.length < 3) return;
        if (activeShape) activeShape.setMap(null);

        // zones.boundary_polygon stores [{lat, lng}, ...] — already the shape
        // google.maps.Polygon paths expect, no transform needed.
        activeShape = new google.maps.Polygon({
            paths: points,
            fillColor: '#4f46e5',
            fillOpacity: 0.15,
            strokeColor: '#4338ca',
            strokeWeight: 2,
            editable: true,
            map: map,
        });

        const bounds = new google.maps.LatLngBounds();
        points.forEach(p => bounds.extend(p));
        map.fitBounds(bounds);

        bindPathListeners();
        syncBoundaryToLivewire();
        setStatus('Existing boundary loaded — drag points to adjust, or Start Drawing to replace it.');
    }

    function syncBoundaryToLivewire() {
        if (!activeShape) return;
        const path = activeShape.getPath().getArray().map(p => ({ lat: p.lat(), lng: p.lng() }));
        setLivewireBoundary(JSON.stringify(path));
    }

    function setLivewireBoundary(json) {
        if (livewireComponent) {
            livewireComponent.set('boundaryPolygonJson', json);
        }
    }

    // Fires when a zone is loaded for editing (see Zones\Form::mount()).
    // Registered globally since this file loads once, not per-component.
    if (window.Livewire) {
        window.Livewire.on('zone-loaded-for-edit', ({ polygon }) => {
            setTimeout(() => drawExistingBoundary(polygon), 100); // wait for the page to paint
        });
    }

    // livewire:navigated fires on every page load (including the first) once
    // Livewire's navigate feature is in play, and on any subsequent SPA-style
    // navigation. boot() is idempotent (initZoneMap no-ops if already
    // initialized), so calling it both ways is safe either way.
    document.addEventListener('livewire:navigated', boot);
    boot();
})();
