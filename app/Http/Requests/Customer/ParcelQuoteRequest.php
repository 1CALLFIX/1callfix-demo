<?php

namespace App\Http\Requests\Customer;

/**
 * POST /api/parcel-orders/quote — the same fields `StoreParcelOrderRequest`
 * accepts for pricing purposes, minus payment/note (irrelevant to a price
 * preview). Deliberately does NOT accept `price_quoted` — there is nothing
 * for a client to override in the first place, matching the mission's
 * "never trust client-provided price" rule structurally, not just by
 * convention.
 */
class ParcelQuoteRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'dropoff_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'package_size' => ['nullable', 'string', 'in:small,medium,large'],
            'delivery_type' => ['nullable', 'string', 'in:now,scheduled'],
            // Stage 2 (2026-10-31): extra drop points (the customer's own saved addresses) and a return trip.
            'extra_stops' => ['nullable', 'array', 'max:9'],
            'extra_stops.*.address_id' => ['required', 'integer'],
            'extra_stops.*.recipient_name' => ['nullable', 'string', 'max:120'],
            'extra_stops.*.recipient_phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'return_trip' => ['nullable', 'boolean'],
        ];
    }
}
