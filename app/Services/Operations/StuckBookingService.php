<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stuck-booking detection (mission Phase 10, item 6). Never invents a new
 * state transition — this is READ-ONLY reporting against the booking's own
 * REAL FSM status + booking_status_history.changed_at (when the booking
 * actually entered its current status) and the already-existing
 * on_hold_since column. Thresholds are Setting-driven operational safety
 * defaults (not commercial values), admin-adjustable, never silently
 * fabricated. Recovery happens through the EXISTING, already-authorized
 * AdminReassignBookingAction/AdminCancelBookingAction on Bookings\Show —
 * this service only surfaces WHICH bookings need attention, it never
 * mutates one itself (no second dispatch/recovery engine).
 */
class StuckBookingService
{
    private const DEFAULT_THRESHOLD_MINUTES = [
        'searching_provider' => 30,
        'assigned' => 60,
        'provider_en_route' => 60,
        'in_progress' => 240,
    ];

    private const ON_HOLD_DEFAULT_MINUTES = 1440; // 24h

    /** Row-level scoped to what this admin can actually see (operations.view), same AuthorizationService::scopeQuery() convention every other screen uses. */
    public function detect(User $user): Collection
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $authz = app(AuthorizationService::class);

        $stuck = collect();

        foreach (self::DEFAULT_THRESHOLD_MINUTES as $status => $defaultMinutes) {
            $thresholdMinutes = (int) Setting::get("operations.stuck_threshold_minutes.{$status}", (string) $defaultMinutes);
            $cutoff = now()->subMinutes($thresholdMinutes);

            $authz->scopeQuery(Booking::query(), $user, 'operations.view', $columns)
                ->where('status', $status)
                ->with('franchise.country')
                ->get()
                ->each(function (Booking $booking) use (&$stuck, $status, $cutoff, $thresholdMinutes) {
                    $enteredAt = $this->statusEnteredAt($booking, $status);
                    if ($enteredAt->lessThan($cutoff)) {
                        $stuck->push([
                            'booking' => $booking, 'status' => $status, 'stuck_since' => $enteredAt,
                            'minutes_stuck' => $enteredAt->diffInMinutes(now()), 'threshold_minutes' => $thresholdMinutes,
                        ]);
                    }
                });
        }

        $holdThreshold = (int) Setting::get('operations.stuck_threshold_minutes.on_hold', (string) self::ON_HOLD_DEFAULT_MINUTES);
        $authz->scopeQuery(Booking::query(), $user, 'operations.view', $columns)
            ->whereNotNull('on_hold_since')
            ->where('on_hold_since', '<', now()->subMinutes($holdThreshold))
            ->with('franchise.country')
            ->get()
            ->each(function (Booking $booking) use (&$stuck, $holdThreshold) {
                $stuck->push([
                    'booking' => $booking, 'status' => 'on_hold', 'stuck_since' => $booking->on_hold_since,
                    'minutes_stuck' => $booking->on_hold_since->diffInMinutes(now()), 'threshold_minutes' => $holdThreshold,
                ]);
            });

        return $stuck->sortByDesc('minutes_stuck')->values();
    }

    /** Falls back to created_at if no matching history row exists (a status this booking never explicitly logged a transition into). */
    private function statusEnteredAt(Booking $booking, string $status): Carbon
    {
        $history = BookingStatusHistory::where('booking_id', $booking->id)->where('status', $status)->latest('changed_at')->first();

        return $history?->changed_at ?? $booking->created_at;
    }
}
