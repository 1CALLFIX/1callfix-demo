<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * One place for the life of an instant booking's "call" (owner request 2026-10-20), so the auto-cancel sweep,
 * the Settings screen and the admin live-calls bar all use the same numbers:
 *
 *   T+0                      dispatch starts (bookings.dispatch_deadline_at)
 *   T+rounds × offer timeout automatic broadcast ends; the booking waits for an admin to assign by hand
 *   T+escalation minutes     one admin alert
 *   T+cancel minutes         auto-cancel (DispatchDeadlineSweepService)
 *
 * Safety lock: the auto-cancel never fires before broadcast + dispatch.min_admin_window_minutes, whatever
 * operations.stuck_threshold_minutes.searching_provider says. Live on 2026-10-19 that threshold was 10 with a
 * 10-minute broadcast, so a booking was cancelled the moment the broadcast ended and the admin had no time.
 */
final class DispatchTimeline
{
    public static function broadcastSeconds(?int $rounds = null, ?int $offerTimeoutSeconds = null): int
    {
        $rounds ??= (int) Setting::get('dispatch.max_rounds', 24);
        $offerTimeoutSeconds ??= (int) Setting::get('dispatch.offer_timeout_seconds', 25);

        return max(0, $rounds) * max(0, $offerTimeoutSeconds);
    }

    /** The configured auto-cancel time (Settings → Operations → Searching provider). */
    public static function configuredCancelMinutes(): int
    {
        return (int) Setting::get('operations.stuck_threshold_minutes.searching_provider', 30);
    }

    /** Minutes an admin always gets, after the broadcast ends, to assign by hand. */
    public static function minAdminWindowMinutes(): int
    {
        return max(0, (int) Setting::get('dispatch.min_admin_window_minutes', 10));
    }

    /** The admin live-calls bar turns red and beeps this many minutes before the auto-cancel. */
    public static function warningMinutes(): int
    {
        return max(0, (int) Setting::get('dispatch.admin_warning_minutes', 5));
    }

    public static function escalationMinutes(): int
    {
        return (int) Setting::get('dispatch.escalation_minutes', 5);
    }

    /** Earliest allowed auto-cancel, in minutes after dispatch starts. */
    public static function floorMinutes(?int $broadcastSeconds = null, ?int $minWindow = null): int
    {
        $broadcastSeconds ??= self::broadcastSeconds();
        $minWindow ??= self::minAdminWindowMinutes();

        return (int) ceil($broadcastSeconds / 60) + $minWindow;
    }

    /** The auto-cancel time the sweep really uses: the configured one, never below the floor. */
    public static function effectiveCancelMinutes(): int
    {
        return max(self::configuredCancelMinutes(), self::floorMinutes());
    }

    /** Null when the numbers leave the admin enough time; otherwise the reason, for a settings save error. */
    public static function problem(int $broadcastSeconds, int $cancelMinutes, int $minWindow): ?string
    {
        $floor = self::floorMinutes($broadcastSeconds, $minWindow);
        if ($cancelMinutes >= $floor) {
            return null;
        }

        return 'Auto-cancel at '.$cancelMinutes.' min is too early: providers are called for '.self::minutesText($broadcastSeconds)
            .' and admins must then get at least '.$minWindow.' min to assign by hand. Use '.$floor.' min or more, or shorten the broadcast.';
    }

    /** Plain-words summary for the Settings screen. */
    public static function describe(int $broadcastSeconds, int $cancelMinutes): string
    {
        $window = max(0, $cancelMinutes * 60 - $broadcastSeconds);

        return 'Providers are called for '.self::minutesText($broadcastSeconds).', then admins have '.self::minutesText($window)
            .' to assign by hand; auto-cancel at '.$cancelMinutes.' min.';
    }

    /**
     * @return array{started_at: Carbon, broadcast_ends_at: Carbon, cancels_at: Carbon, warn_at: Carbon}|null
     */
    public static function forBooking(Booking $booking): ?array
    {
        if (! $booking->dispatch_deadline_at || $booking->scheduled_at !== null) {
            return null;
        }

        $start = $booking->dispatch_deadline_at->copy();
        $cancelsAt = $start->copy()->addMinutes(self::effectiveCancelMinutes());

        return [
            'started_at' => $start,
            'broadcast_ends_at' => $start->copy()->addSeconds(self::broadcastSeconds()),
            'cancels_at' => $cancelsAt,
            'warn_at' => $cancelsAt->copy()->subMinutes(self::warningMinutes()),
        ];
    }

    private static function minutesText(int $seconds): string
    {
        $minutes = round($seconds / 60, 1);

        return ($minutes == (int) $minutes ? (int) $minutes : $minutes).' min';
    }
}
