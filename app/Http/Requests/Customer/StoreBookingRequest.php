<?php

namespace App\Http\Requests\Customer;

use App\Models\Setting;

/**
 * POST /api/bookings — deliberately does NOT accept `price_quoted`,
 * `franchise_id`, `zone_id`, or `customer_id`: every one of those is
 * server-derived (BookingController::store()) rather than client-supplied,
 * per the mission's own "never trust client-provided price/customer_id"
 * rule. `scheduled_at`'s max-lead-time window reuses the exact
 * `booking.max_schedule_days_ahead` Setting key
 * `Livewire\Bookings\Index::createBooking()` already validates against, so
 * the call-center form and the Customer API can never silently diverge on
 * how far ahead a booking may be scheduled.
 */
class StoreBookingRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        $maxDays = (int) Setting::get('booking.max_schedule_days_ahead', 14);

        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'payment_method' => ['nullable', 'string', 'in:online,cash,wallet'],
            'scheduled_at' => [
                'nullable', 'date', 'after:now',
                'before_or_equal:'.now()->addDays($maxDays)->toDateTimeString(),
            ],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
