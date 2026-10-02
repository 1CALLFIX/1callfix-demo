<?php

namespace App\Http\Requests\Customer;

/** POST /api/bookings/{id}/cancel — same required, non-empty reason `Livewire\Bookings\Show::cancel()` already requires of an admin. */
class CancelBookingRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            // REF 1CF-CANCEL-POLICY-001 — required only when a charge applies; comes from GET /bookings/{id}/cancel-quote
            'quote_token' => ['nullable', 'string', 'max:200'],
        ];
    }
}
