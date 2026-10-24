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
            // Pay at: Pickup | Drop (owner decision 2026-10-24) — cash only; drop = the recipient pays the rider.
            'cash_collect_at' => ['nullable', 'string', 'in:pickup,drop'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'pickup_contact_name' => ['nullable', 'string', 'max:120'],
            'pickup_contact_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ];
    }
}
