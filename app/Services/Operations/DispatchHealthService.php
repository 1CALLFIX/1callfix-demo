<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Models\TaxiRide;
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
 *
 * Extended (Admin Command Center mission, Operations phase) to cover the
 * three other real dispatch flows this screen was blind to: Parcel/Taxi/
 * Marketplace all write dispatch_attempts via the SAME table's polymorphic
 * dispatchable_type/dispatchable_id pair (Phase 22.4 onward), through the
 * SAME FieldWorker-offer mechanics as Booking, but this service only ever
 * queried the booking_id column, so a stale Parcel/Taxi/Marketplace offer
 * or an exhausted order never surfaced here — an operator had no way to
 * know dispatch was stuck for those verticals short of a raw DB query.
 * Deliberately kept as separate result keys (stale_order_offers/
 * exhausted_orders) rather than merged into stale_offers/exhausted_bookings
 * — Booking and these three Orderable models are different shapes
 * (different customer/franchise access paths, different route names for
 * the "view" link), so a merged collection would need per-row type
 * branching either way; keeping them apart also means this change adds
 * data without touching any assertion the existing Booking-only tests make
 * about stale_offers'/exhausted_bookings' exact contents.
 */
class DispatchHealthService
{
    /** Same shape as Parcel/Taxi/Marketplace's own admin screens' scopeColumns() — kept identical on purpose so a grant that already covers those list screens covers this visibility too. */
    private function orderScopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /** Order model => the status value that means "actively searching for a worker" for that vertical (see each DispatchJob's own status handling). */
    private const SEARCHING_STATUS = [
        ParcelOrder::class => 'searching_worker',
        TaxiRide::class => 'searching_driver',
        // MarketplaceOrder has no separate searching status -- `ready` covers
        // both "waiting for a rider" and "waiting for pickup" (architecture
        // doc §4a) -- filtered by order_type below instead.
        MarketplaceOrder::class => 'ready',
    ];

    public function stats(User $user): array
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $authz = app(AuthorizationService::class);

        $offerTimeoutMinutes = (int) Setting::get('dispatch.offer_response_timeout_minutes', '2');
        $staleCutoff = now()->subMinutes($offerTimeoutMinutes);

        $staleOffers = DispatchAttempt::where('status', 'notified')
            ->where('notified_at', '<', $staleCutoff)
            ->whereHas('booking', fn ($q) => $authz->scopeQuery($q, $user, 'operations.view', $columns))
            ->with(['booking.franchise.country', 'provider.user'])
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

        $staleOrderOffers = $this->staleOrderOffers($authz, $user, $staleCutoff);
        $exhaustedOrders = $this->exhaustedOrders($authz, $user);

        return [
            'stale_offers' => $staleOffers,
            'stale_offer_count' => $staleOffers->count(),
            'exhausted_bookings' => $exhaustedBookings,
            'exhausted_booking_count' => $exhaustedBookings->count(),
            'stale_order_offers' => $staleOrderOffers,
            'stale_order_offer_count' => $staleOrderOffers->count(),
            'exhausted_orders' => $exhaustedOrders,
            'exhausted_order_count' => $exhaustedOrders->count(),
        ];
    }

    private function staleOrderOffers(AuthorizationService $authz, User $user, \Illuminate\Support\Carbon $staleCutoff)
    {
        $columns = $this->orderScopeColumns();

        return DispatchAttempt::where('status', 'notified')
            ->where('notified_at', '<', $staleCutoff)
            ->whereHasMorph(
                'dispatchable',
                array_keys(self::SEARCHING_STATUS),
                fn ($q) => $authz->scopeQuery($q, $user, 'operations.view', $columns)
            )
            ->with(['dispatchable.franchise.country', 'notifiable.user'])
            ->latest('notified_at')
            ->limit(50)
            ->get();
    }

    private function exhaustedOrders(AuthorizationService $authz, User $user)
    {
        $columns = $this->orderScopeColumns();

        $results = collect();

        foreach (self::SEARCHING_STATUS as $orderClass => $searchingStatus) {
            $query = $authz->scopeQuery($orderClass::query(), $user, 'operations.view', $columns)
                ->where('status', $searchingStatus)
                ->whereNull('assigned_worker_id')
                ->whereDoesntHave('dispatchAttempts', fn ($q) => $q->where('status', 'notified'))
                ->whereHas('dispatchAttempts')
                ->with('customer');

            if ($orderClass === MarketplaceOrder::class) {
                $query->where('order_type', 'delivery');
            }

            $results = $results->merge($query->latest('id')->limit(50)->get());
        }

        return $results->sortByDesc('id')->take(50)->values();
    }
}
