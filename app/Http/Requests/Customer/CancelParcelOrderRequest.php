<?php

namespace App\Http\Requests\Customer;

/** POST /api/parcel-orders/{id}/cancel — same required, non-empty reason convention as CancelBookingRequest. */
class CancelParcelOrderRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
