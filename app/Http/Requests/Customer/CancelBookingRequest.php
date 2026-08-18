<?php

namespace App\Http\Requests\Customer;

/** POST /api/bookings/{id}/cancel — same required, non-empty reason `Livewire\Bookings\Show::cancel()` already requires of an admin. */
class CancelBookingRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
