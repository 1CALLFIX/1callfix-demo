<?php

namespace App\Http\Requests\Customer;

/**
 * POST /api/parcel-orders — deliberately does NOT accept `price_quoted`,
 * `franchise_id`, `zone_id`, or `customer_id`: every one of those is
 * server-derived (`ParcelOrderController::store()`), same principle
 * `StoreBookingRequest` already established for Service booking.
 */
class StoreParcelOrderRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'dropoff_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'package_description' => ['nullable', 'string', 'max:255'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'package_size' => ['nullable', 'string', 'in:small,medium,large'],
            'payment_method' => ['nullable', 'string', 'in:online,cash,wallet'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
