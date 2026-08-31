<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\TimezoneResolver;
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
            // The customer typed a naive wall clock (Asia/Kolkata today);
            // parse it in that zone so "is it in the past / within the
            // window" is judged against the customer's real clock, not a
            // UTC reading 5.5h off. Carbon comparisons are instant-based,
            // so comparing against now() (UTC) stays correct.
            $when = Carbon::parse($raw, app(TimezoneResolver::class)->platformTimezone());
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

    /**
     * The stored form: null for ASAP, else a UTC Carbon. The datetime-local
     * value is a naive wall clock in the customer's timezone (Asia/Kolkata
     * today); TimezoneResolver::toUtc() interprets it there and converts,
     * so scheduled_at is stored as a correct UTC instant. Assumes
     * validate() already passed.
     */
    public static function parse(?string $raw): ?Carbon
    {
        return app(TimezoneResolver::class)->toUtc($raw);
    }
}
