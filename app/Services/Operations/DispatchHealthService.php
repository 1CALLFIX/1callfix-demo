<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * Auto-assignment/dispatch health (mission Phase 10, item 8). Reuses the
 * EXISTING dispatch_attempts table DispatchService already writes — no
 * second dispatch engine, purely read-only observation of what already
 * happened. Real signals only: a 'notified' attempt whose notified_at is
 * older than the configured offer-response window (Setting-driven,
 * DispatchService's own timeout convention) but never got a responded_at
 * is a stale/un-actioned offer; a booking stuck in 'searching_provider'
 * with every attempt against it in a terminal state (rejected/timeout) is
 * a genuine "no provider currently available" case.
 */
class DispatchHealthService
{
    public function stats(User $user): array
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $authz = app(AuthorizationService::class);

        $offerTimeoutMinutes = (int) Setting::get('dispatch.offer_response_timeout_minutes', '2');
        $staleCutoff = now()->subMinutes($offerTimeoutMinutes);

        $staleOffers = DispatchAttempt::where('status', 'notified')
            ->where('notified_at', '<', $staleCutoff)
            ->whereHas('booking', fn ($q) => $authz->scopeQuery($q, $user, 'operations.view', $columns))
            ->with(['booking', 'provider.user'])
            ->latest('notified_at')
            ->limit(50)
            ->get();

        $exhaustedBookings = $authz->scopeQuery(Booking::query(), $user, 'operations.view', $columns)
            ->where('status', 'searching_provider')
            ->whereDoesntHave('dispatchAttempts', fn ($q) => $q->where('status', 'notified'))
            ->whereHas('dispatchAttempts')
            ->with('customer')
            ->latest('id')
            ->limit(50)
            ->get();

        return [
            'stale_offers' => $staleOffers,
            'stale_offer_count' => $staleOffers->count(),
            'exhausted_bookings' => $exhaustedBookings,
            'exhausted_booking_count' => $exhaustedBookings->count(),
        ];
    }
}
