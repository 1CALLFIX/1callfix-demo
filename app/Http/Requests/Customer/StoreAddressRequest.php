<?php

namespace App\Http\Requests\Customer;

/**
 * POST /api/addresses — `zone_id` is required (not geocoded from lat/lng:
 * no point-in-polygon/geofencing resolver exists anywhere in this codebase
 * — `Zone.boundary_polygon` is stored but nothing ever queries it — the
 * ONLY established zone-resolution mechanism in the entire codebase is
 * explicit selection, exactly like the admin booking form's own zone
 * dropdown). `franchise_id` is deliberately NOT accepted here — the
 * controller derives it from the chosen zone server-side, so a client can
 * never submit a zone/franchise pair that don't actually belong together.
 */
class StoreAddressRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address_line' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
