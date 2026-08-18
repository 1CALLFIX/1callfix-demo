<?php

namespace App\Http\Requests\Customer;

/** POST /api/taxi-rides/{id}/cancel — same required, non-empty reason convention as CancelBookingRequest. */
class CancelTaxiRideRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
