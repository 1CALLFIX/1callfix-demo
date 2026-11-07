/**
 * Pin-on-map for the customer address forms (owner 2026-11-07). Markup: resources/views/components/customer/address-map.blade.php.
 *
 * The red pin is fixed over the map's centre; the customer moves the map under it. Each time the map comes to rest:
 *   1. Google's address for the spot fills the form's EMPTY street / city / PIN fields (a field the customer typed is
 *      never overwritten; a field this file filled is updated when the pin moves again),
 *   2. the form's own useCurrentLocationForNewAddress(lat, lng) checks the service area and keeps the pin for save().
 * "Use my current location" (geolocation.js) and the search box only move the map; step 1–2 then follow.
 *
 * Google Maps is loaded on first use with the same browser key the page already carries (window.CF_GOOGLE_PLACES).
 */

let mapsPromise = null;

function loadMaps() {
    if (window.google?.maps?.Map) {
        return Promise.resolve();
    }
    if (!mapsPromise) {
        const key = window.CF_GOOGLE_PLACES?.key;
        if (!key) {
            return Promise.reject(new Error('no key'));
        }
        mapsPromise = new Promise((resolve, reject) => {
            window.__cfMapsReady = resolve;
            const s = document.createElement('script');
            s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly&callback=__cfMapsReady`;
            s.async = true;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }
    return mapsPromise;
}

/** Street / city / PIN from a Geocoder result. */
function partsOf(result) {
    const get = (...types) => {
        const c = result.address_components.find((a) => types.some((t) => a.types.includes(t)));
        return c ? c.long_name : '';
    };
    const street = [get('premise', 'street_number'), get('route'), get('sublocality_level_2'), get('sublocality_level_1', 'sublocality', 'neighborhood')]
        .filter(Boolean)
        .filter((v, i, all) => all.indexOf(v) === i)
        .join(', ');
    return {
        address_line: street,
        city: get('locality', 'administrative_area_level_3', 'administrative_area_level_2'),
        pincode: get('postal_code'),
    };
}

function initAddressMap(el) {
    if (el.dataset.ready === '1') {
        return;
    }
    el.dataset.ready = '1';

    const root = el.closest('[wire\\:id]');
    const wire = root && window.Livewire ? window.Livewire.find(root.getAttribute('wire:id')) : null;
    if (!wire) {
        return;
    }
    const prefix = el.dataset.prefix;
    const fields = JSON.parse(el.dataset.fields || '[]');
    const autoFilled = {}; // field => the value this file put there last
    const notFound = el.querySelector('[data-address-map-notfound]');

    loadMaps().then(() => {
        const start = { lat: parseFloat(el.dataset.lat), lng: parseFloat(el.dataset.lng) };
        const pinned = el.dataset.pinned === '1';
        const map = new google.maps.Map(el.querySelector('[data-address-map-canvas]'), {
            center: start,
            zoom: pinned ? 18 : 14,
            disableDefaultUI: true,
            zoomControl: true,
            gestureHandling: 'greedy',
            clickableIcons: false,
        });
        const geocoder = new google.maps.Geocoder();
        let userMoved = false;
        let timer = null;

        const settle = () => {
            const c = map.getCenter();
            const lat = c.lat();
            const lng = c.lng();
            geocoder.geocode({ location: { lat, lng } })
                .then(({ results }) => {
                    if (!results || !results[0]) {
                        return;
                    }
                    const parts = partsOf(results[0]);
                    fields.forEach((f) => {
                        const now = (wire.get(`${prefix}.${f}`) || '').toString().trim();
                        if (parts[f] && (now === '' || now === autoFilled[f])) {
                            wire.set(`${prefix}.${f}`, parts[f], false);
                            autoFilled[f] = parts[f];
                        }
                    });
                })
                .catch(() => {})
                .finally(() => wire.useCurrentLocationForNewAddress(lat, lng));
        };

        // Count the customer's own moves only from the first rest on: the map's own start-up zoom must not pin the
        // area centre as if the customer had chosen it.
        google.maps.event.addListenerOnce(map, 'idle', () => {
            map.addListener('dragstart', () => { userMoved = true; });
            map.addListener('zoom_changed', () => { userMoved = true; });
        });
        map.addListener('idle', () => {
            // The first rest is just the page opening on the area's centre: not a pin until the customer moves it
            // (an address being edited is already pinned, so nothing to do either).
            if (!userMoved) {
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(settle, 350);
        });

        const goTo = (lat, lng) => {
            userMoved = true;
            map.setZoom(18);
            map.panTo({ lat, lng });
        };

        // "Use my current location" (geolocation.js) and anything else that finds the customer.
        window.addEventListener('cf-address-located', (e) => {
            if (el.isConnected) {
                goTo(e.detail.lat, e.detail.lng);
            }
        });

        const search = el.querySelector('[data-address-map-search]');
        const find = () => {
            const q = search.value.trim();
            if (q.length < 3) {
                return;
            }
            notFound.hidden = true;
            geocoder.geocode({ address: q, bounds: map.getBounds(), componentRestrictions: { country: el.dataset.region || 'IN' } })
                .then(({ results }) => {
                    const loc = results?.[0]?.geometry?.location;
                    if (loc) {
                        goTo(loc.lat(), loc.lng());
                    } else {
                        notFound.hidden = false;
                    }
                })
                .catch(() => { notFound.hidden = false; });
        };
        el.querySelector('[data-address-map-find]').addEventListener('click', find);
        search.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                find();
            }
        });
    }).catch(() => {
        // No key or Google unreachable: hide the map, the form still works by hand.
        el.hidden = true;
    });
}

function initAll(scope = document) {
    scope.querySelectorAll('[data-address-map]').forEach(initAddressMap);
}

document.addEventListener('DOMContentLoaded', () => initAll());
document.addEventListener('livewire:navigated', () => initAll());
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morphed', ({ el }) => initAll(el));
});
