<?php

namespace App\Http\Requests\Customer;

/**
 * POST /api/taxi-rides/quote — `dropoff_address_id` is optional, mirroring
 * `taxi_rides.dropoff_address_id` itself being nullable (a real ride can be
 * requested with just a pickup, destination decided en route — the same
 * shape `CreateTaxiRideAction::execute()` already accepts).
 */
class TaxiQuoteRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'dropoff_address_id' => ['nullable', 'integer', 'exists:addresses,id'],
        ];
    }
}
