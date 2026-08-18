<?php

namespace App\Http\Requests\Customer;

/**
 * POST /api/taxi-rides — same "never trust client-provided price/scope"
 * principle as StoreBookingRequest/StoreParcelOrderRequest. No `driver_id`
 * field exists anywhere in this Request or `CreateTaxiRideAction` itself —
 * driver assignment is entirely `TaxiDispatchJob`'s job, a customer can
 * never submit one.
 */
class StoreTaxiRideRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'dropoff_address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'payment_method' => ['nullable', 'string', 'in:online,cash,wallet'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
