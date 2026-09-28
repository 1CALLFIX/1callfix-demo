<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\TimezoneResolver;
use Illuminate\Support\Carbon;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 1) — computes the actual list of
 * selectable scheduling slots per day, from three inputs:
 *
 *   - the service window (booking.service_window_start_hour /
 *     booking.service_window_end_hour — NEW settings this phase adds;
 *     this codebase had no admin-configurable "what hours can a booking be
 *     scheduled for" concept before this, only the existing
 *     booking.max_schedule_days_ahead day-count limit, which stays
 *     untouched and is reused verbatim via BookingSchedule::maxDays()),
 *   - the ONE unified scheduling buffer (BookingSchedule::bufferMinutes()
 *     — 30 or 60 minutes, admin-selectable, default 30), and
 *   - the platform timezone (TimezoneResolver — the same one
 *     BookingSchedule::validate()/parse() already use).
 *
 * Slot granularity is deliberately the buffer itself, not a second
 * "slot interval" setting — the brief is explicit that only ONE buffer
 * setting may exist. With a 30-minute buffer, slots fall on the half-hour;
 * with 60, on the hour. This also guarantees the "final slot = window end
 * minus buffer" rule always lands exactly on a generated slot rather than
 * needing a special-cased extra entry, as long as the admin configures a
 * window whose length (in minutes) is a multiple of the buffer — true for
 * any whole-hour window against a 30 or 60 minute buffer.
 *
 * THE RULES (verbatim from the brief, and the reason this class exists):
 *
 *   TODAY: a slot is valid only when slot_time >= now + buffer. Once today
 *   has no valid slots left, TODAY is omitted entirely (not returned with
 *   an empty list — the caller can tell "no more slots" from "this key
 *   doesn't exist" without an extra check).
 *
 *   FUTURE DAYS: the "now" rule does NOT apply at all — every configured
 *   slot from window-start up to window-end-minus-buffer is offered,
 *   regardless of what time "now" currently is. This is the fix for the
 *   documented bug class ("future days lose morning slots, appearing to
 *   begin around 11am") — that bug shape is what happens when the TODAY
 *   lead-time filter is accidentally applied uniformly to every day
 *   instead of only the current one. There was no pre-existing slot-picker
 *   implementation anywhere in this codebase to literally reproduce that
 *   regression in (confirmed by search — only a free-form datetime-local
 *   input existed, via BookingSchedule), so this class is written to keep
 *   the two rules structurally separate from the start, rather than
 *   "fixing" code that was never there.
 */
class BookingScheduleSlots
{
    public function windowStartHour(array $scope = []): int
    {
        return max(0, min(23, (int) Setting::get('booking.service_window_start_hour', '8', $scope)));
    }

    public function windowEndHour(array $scope = []): int
    {
        return max(1, min(24, (int) Setting::get('booking.service_window_end_hour', '20', $scope)));
    }

    public function bufferMinutes(): int
    {
        return BookingSchedule::bufferMinutes();
    }

    public function daysAhead(): int
    {
        return BookingSchedule::maxDays();
    }

    /**
     * @return array<string, list<Carbon>> date ("Y-m-d") => ordered list of
     *         selectable slot instants (Carbon, platform timezone), for
     *         today through daysAhead() days ahead. A date with zero valid
     *         slots (only ever possible for today) is omitted from the
     *         array entirely.
     */
    public function availableSlots(array $scope = [], ?Carbon $referenceNow = null): array
    {
        $tz = app(TimezoneResolver::class)->platformTimezone();
        $now = ($referenceNow ?? now())->copy()->setTimezone($tz);

        $out = [];

        for ($dayOffset = 0; $dayOffset <= $this->daysAhead(); $dayOffset++) {
            $day = $now->copy()->addDays($dayOffset)->startOfDay();
            $isToday = $dayOffset === 0;

            $slots = $this->slotsForDay($day, $scope, $isToday ? $now : null);

            if ($slots !== []) {
                $out[$day->format('Y-m-d')] = $slots;
            }
        }

        return $out;
    }

    /**
     * @param  ?Carbon  $lowerBoundNow  non-null ONLY for today — every slot
     *         must be >= this + buffer. Null for any future day: the "now"
     *         rule simply does not apply there (see class docblock).
     * @return list<Carbon>
     */
    private function slotsForDay(Carbon $day, array $scope, ?Carbon $lowerBoundNow): array
    {
        $buffer = $this->bufferMinutes();
        $windowStart = $day->copy()->setTime($this->windowStartHour($scope), 0);
        // The final selectable slot is window-end MINUS buffer — never
        // window-end itself (a job starting exactly at closing time, with
        // no buffer margin, is not a real slot).
        $lastSlot = $day->copy()->setTime($this->windowEndHour($scope), 0)->subMinutes($buffer);

        $todayFloor = $lowerBoundNow ? $lowerBoundNow->copy()->addMinutes($buffer) : null;

        $slots = [];
        $cursor = $windowStart->copy();

        while ($cursor->lte($lastSlot)) {
            if ($todayFloor === null || $cursor->gte($todayFloor)) {
                $slots[] = $cursor->copy();
            }
            $cursor->addMinutes($buffer);
        }

        return $slots;
    }
}
