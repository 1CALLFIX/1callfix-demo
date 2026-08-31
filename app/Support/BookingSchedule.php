<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The one place the customer web decides whether a chosen booking time is
 * acceptable: empty means ASAP (allowed), otherwise it must be a real
 * datetime, in the future, and within `booking.max_schedule_days_ahead`.
 *
 * Extracted verbatim from App\Livewire\Customer\Booking\Wizard so the
 * booking wizard, the "add to cart" control on the service page, and the
 * cart checkout all apply the identical rule with no second copy to drift.
 */
class BookingSchedule
{
    public static function maxDays(): int
    {
        return (int) Setting::get('booking.max_schedule_days_ahead', 14);
    }

    /**
     * Null when the value is acceptable (ASAP, or an in-window datetime).
     * Otherwise the message to show the customer.
     */
    public static function validate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null; // ASAP
        }

        try {
            $when = Carbon::parse($raw);
        } catch (\Throwable) {
            return 'That does not look like a valid date and time.';
        }

        if ($when->isPast()) {
            return 'Pick a time in the future.';
        }

        if ($when->greaterThan(now()->addDays(self::maxDays()))) {
            return 'We can only schedule up to '.self::maxDays().' days ahead.';
        }

        return null;
    }

    /** The stored form: null for ASAP, a Carbon otherwise. Assumes validate() already passed. */
    public static function parse(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return Carbon::parse($raw);
    }
}
