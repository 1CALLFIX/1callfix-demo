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
     * REF 1CF-SCHEDULING-DISPATCH-001 (Part 1) — the ONE customer
     * scheduling buffer setting, admin-selectable 30 or 60 minutes,
     * default 30. Used for: (1) minimum lead time for a TODAY slot here in
     * validate(), (2) the last selectable slot on any day
     * (service-window-end minus buffer — see BookingScheduleSlots), and
     * (3) the final-scheduled-booking escalation point before
     * scheduled_at (ScheduledBookingEscalationService::urgentAlerts()).
     * Deliberately the ONLY buffer-shaped setting this phase adds — do not
     * add a second one.
     *
     * Clamped to {30, 60}: any other stored value (a bad migration, a
     * hand-edited row) falls back to the 30-minute default rather than
     * silently accepting an arbitrary number the brief never allowed.
     */
    public static function bufferMinutes(): int
    {
        $raw = (int) Setting::get('booking.scheduling_buffer_minutes', '30');

        return in_array($raw, [30, 60], true) ? $raw : 30;
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

        // Part 1's TODAY lead-time rule: slot_time >= now + buffer. Only
        // applied when the chosen date is today (in the platform
        // timezone) — a future day is never subject to the "now" rule at
        // all (see BookingScheduleSlots's own docblock for the bug this
        // distinction exists to prevent).
        $platformNow = now(app(TimezoneResolver::class)->platformTimezone());
        if ($when->isSameDay($platformNow) && $when->lessThan($platformNow->copy()->addMinutes(self::bufferMinutes()))) {
            return 'Pick a time at least '.self::bufferMinutes().' minutes from now.';
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
