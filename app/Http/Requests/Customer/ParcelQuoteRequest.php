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
        ];
    }
}
