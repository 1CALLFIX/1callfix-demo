{{--
    Pin-on-map for the customer add / edit address forms (owner 2026-11-07: "Use my current location" found the area but
    filled nothing, and there was no way to point at the door). Swiggy / Urban Company pattern: the pin stays in the middle,
    the customer moves the map under it. When the map stops, resources/js/address-map.js
      - fills the empty street / city / PIN fields from Google's address for that spot (never overwrites what was typed),
      - calls the form's own useCurrentLocationForNewAddress(lat, lng), which checks the service area and keeps the pin.
    Drawn only when the Google Maps key is configured; without it the form works as before.

    Props: prefix = the Livewire array holding the fields ('form' / 'newAddress'); fields = which of
    address_line / city / pincode that form has; lat / lng = where to start (an address being edited, else the
    customer's area); pinned = true when lat/lng is the address's own spot.
--}}
@props(['prefix', 'fields' => ['address_line', 'city', 'pincode'], 'lat' => null, 'lng' => null, 'pinned' => false])

@if (config('services.google_maps.key'))
    @php
        $zone = app(\App\Services\Customer\CustomerLocationContext::class)->zone();
        $startLat = $lat ?? $zone?->center_lat ?? 14.4426;
        $startLng = $lng ?? $zone?->center_lng ?? 79.9865;
    @endphp
    <div wire:ignore data-address-map {{ $attributes }}
         data-prefix="{{ $prefix }}" data-fields='@json(array_values($fields))'
         data-lat="{{ $startLat }}" data-lng="{{ $startLng }}" data-pinned="{{ $pinned ? '1' : '0' }}"
         data-region="{{ config('services.google_maps.places_region') ?: 'IN' }}"
         class="mt-3">
        <div class="flex gap-2">
            <input type="search" data-address-map-search enterkeyhint="search"
                   placeholder="{{ \App\Support\SiteText::get('address_map.search') }}"
                   aria-label="{{ \App\Support\SiteText::get('address_map.search') }}"
                   class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm">
            <button type="button" data-address-map-find
                    class="min-h-11 shrink-0 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-800 hover:bg-slate-50">
                {{ \App\Support\SiteText::get('address_map.find') }}
            </button>
        </div>
        <div class="relative mt-2 h-56 overflow-hidden rounded-xl border border-slate-200 bg-slate-100 sm:h-64">
            <div data-address-map-canvas class="absolute inset-0"></div>
            {{-- The pin is drawn over the map's centre; its tip marks the spot. --}}
            <svg aria-hidden="true" class="pointer-events-none absolute left-1/2 top-1/2 h-10 w-10 -translate-x-1/2 -translate-y-full drop-shadow" viewBox="0 0 24 24">
                <path fill="#dc2626" d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7z"/>
                <circle cx="12" cy="9" r="2.6" fill="#fff"/>
            </svg>
        </div>
        <p data-address-map-hint class="mt-1.5 text-xs text-slate-500">{{ \App\Support\SiteText::get('address_map.hint') }}</p>
        <p data-address-map-notfound hidden class="mt-1 text-xs text-rose-600">{{ \App\Support\SiteText::get('address_map.not_found') }}</p>
    </div>
@endif
