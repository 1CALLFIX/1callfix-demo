<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Provider;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Models\Zone;
use App\Services\Ranking\RankingConfigResolver;
use App\Services\Ranking\RankingEngine;
use Illuminate\Support\Collection;

class DispatchService
{
    public function __construct(
        private RankingEngine $rankingEngine,
        private RankingConfigResolver $rankingConfigResolver,
    ) {
    }

    /**
     * Find eligible providers for a booking, ranked by the admin-configured
     * provider ranking rule for this booking's scope (see Settings >
     * Ranking) — distance-ascending only by default, identical to this
     * method's behaviour before the ranking engine existed.
     *
     * Eligibility:
     *   - Same zone as the booking
     *   - Online right now
     *   - KYC approved, account active
     *   - Skills include this service's category
     *   - Not currently tied up on another active booking
     *   - Not excluded for this specific booking — see excludedProviderIdsForBooking()
     *     for exactly what that means (NOT simply "any dispatch_attempts row exists")
     *   - Within the zone's dispatch radius (Haversine distance from customer's address)
     *
     * @return Collection<int, array{provider: Provider, distance_km: float}>
     */
    public function findCandidates(Booking $booking, int $limit = 5): Collection
    {
        $booking->loadMissing(['zone', 'address', 'service', 'franchise']);

        if (!$booking->zone || !$booking->address) {
            return collect();
        }

        $radiusKm = $booking->zone->default_dispatch_radius_km ?? 8;
        $categoryId = $booking->service->category_id;

        $scope = array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);

        $excludedProviderIds = $this->excludedProviderIdsForBooking($booking, $scope);

        // 'on_hold' counts as busy too — a paused job (awaiting spares, customer
        // approval, or a provider-side red flag) still ties up that provider,
        // it's not a signal that they're free for new work.
        $busyProviderIds = Booking::whereIn('status', ['assigned', 'provider_en_route', 'in_progress', 'on_hold'])
            ->whereNotNull('provider_id')
            ->pluck('provider_id');

        $candidates = $this->eligibleQuery($booking->zone_id, $categoryId)
            ->whereNotIn('id', $excludedProviderIds)
            ->whereNotIn('id', $busyProviderIds)
            ->get()
            ->filter(fn (Provider $provider) => $this->hasSkill($provider, $categoryId))
            ->map(fn (Provider $provider) => $this->withDistance($provider, (float) $booking->address->lat, (float) $booking->address->lng))
            ->filter(fn ($c) => $c['distance_km'] <= $radiusKm);

        return $this->rankAndLimit($candidates, $scope, $radiusKm, $limit);
    }

    /**
     * Which providers this booking must not be (re-)offered to in the next
     * round. Fixes the "single-provider dispatch trap" (real incident:
     * booking #136 / NLR-2208-00000076 — a zone with exactly one available
     * provider got permanently stuck in `searching_provider` after that
     * provider missed one offer window, since the old logic excluded a
     * provider for the rest of the booking's life the moment ANY
     * dispatch_attempts row existed for them, with no regard for its status).
     *
     * `dispatch_attempts.status` as the schema has always defined it
     * (`2026_08_01_020000_create_dispatch_attempts_table.php`):
     * notified | accepted | rejected | timeout. Confirmed by reading every
     * write path in this codebase that 'rejected' has never actually been
     * written by any of them — there is no provider-facing "decline this
     * offer" action anywhere (ProviderAnomalyService's own docblock
     * independently confirms the same audit: a dispatch_attempts row only
     * ever becomes 'accepted' or 'timeout' today). The column was already
     * there for it; the application just never used it. Handled here so an
     * explicit decline, whenever one is added, is correctly permanent
     * without this method needing to change again.
     *
     * Exclusion rules:
     *   - 'notified'  -- offer still open this round: excluded (don't double-offer).
     *   - 'accepted'  -- provider took the job: excluded (moot; also covered by
     *                    the busy-provider check once assigned, but explicit here).
     *   - 'rejected'  -- explicit decline: excluded permanently for this booking.
     *   - 'timeout'   -- NOT permanently excluded. A provider who missed one
     *                    offer window is still eligible for a later round,
     *                    UNLESS they've timed out on this specific booking
     *                    dispatch.max_timeouts_per_provider times already —
     *                    the circuit breaker, so a provider whose "online"
     *                    flag is stale (never actually responding) doesn't
     *                    get re-offered the same job every single round
     *                    forever. Other providers in the zone are unaffected
     *                    and keep cycling through rounds normally.
     */
    private function excludedProviderIdsForBooking(Booking $booking, array $scope): Collection
    {
        $attempts = $booking->dispatchAttempts()->get(['provider_id', 'status']);

        $permanentlyExcluded = $attempts
            ->whereIn('status', ['notified', 'accepted', 'rejected'])
            ->pluck('provider_id');

        $maxTimeouts = (int) Setting::get('dispatch.max_timeouts_per_provider', 3, $scope);

        $circuitBrokenByTimeouts = $attempts
            ->where('status', 'timeout')
            ->groupBy('provider_id')
            ->filter(fn (Collection $group) => $group->count() >= $maxTimeouts)
            ->keys();

        return $permanentlyExcluded->merge($circuitBrokenByTimeouts)->unique()->values();
    }

    /**
     * Phase E4 — does THIS specific provider pass every non-ranking
     * eligibility rule findCandidates() applies for THIS booking?
     *
     * Used by bundle dispatch consolidation (BundleConsolidationJob) to
     * decide whether the provider who just accepted one bundle child may be
     * offered a sibling directly. Reuses the exact same helpers
     * findCandidates() uses (eligibleQuery / hasSkill / withDistance /
     * haversineKm), so the two can never drift apart.
     *
     * Deliberately does NOT apply:
     *   - the $busyProviderIds filter — a provider holding one bundle child
     *     is "busy" by that coarse rule, but consolidation's whole point is
     *     to let them hold non-overlapping siblings; time-overlap is checked
     *     separately by ProviderAvailabilityService.
     *   - excludedProviderIdsForBooking() — a consolidation offer is a fresh,
     *     deliberate offer to a hand-picked provider, not another blind
     *     dispatch round.
     *   - ranking — this is a yes/no gate, not an ordering.
     */
    public function providerEligibleForBooking(Provider $provider, Booking $booking): bool
    {
        $booking->loadMissing(['zone', 'address', 'service']);

        if (! $booking->zone || ! $booking->address || ! $booking->service) {
            return false;
        }

        if ((int) $provider->zone_id !== (int) $booking->zone_id) {
            return false;
        }

        if (! $provider->is_online || ! $provider->is_active || $provider->kyc_status !== 'approved') {
            return false;
        }

        if ($provider->current_lat === null || $provider->current_lng === null) {
            return false;
        }

        $categoryId = $booking->service->category_id;

        if (! $this->hasSkill($provider, $categoryId)) {
            return false;
        }

        $radiusKm = $booking->zone->default_dispatch_radius_km ?? 8;
        $distanceKm = $this->haversineKm(
            (float) $booking->address->lat,
            (float) $booking->address->lng,
            (float) $provider->current_lat,
            (float) $provider->current_lng,
        );

        return $distanceKm <= $radiusKm;
    }

    /**
     * Read-only "browse nearby providers" — the customer-facing counterpart
     * to findCandidates() above: same eligibility/ranking machinery, but no
     * booking exists yet, so the busy/already-offered exclusions (which
     * only make sense against a specific booking) don't apply. Used by
     * GET /api/providers/nearby — the second real ranking consumer,
     * alongside dispatch itself.
     */
    public function nearbyForService(Service $service, Zone $zone, float $lat, float $lng, int $limit = 20): Collection
    {
        $radiusKm = $zone->default_dispatch_radius_km ?? 8;
        $categoryId = $service->category_id;

        $candidates = $this->eligibleQuery($zone->id, $categoryId)
            ->get()
            ->filter(fn (Provider $provider) => $this->hasSkill($provider, $categoryId))
            ->map(fn (Provider $provider) => $this->withDistance($provider, $lat, $lng))
            ->filter(fn ($c) => $c['distance_km'] <= $radiusKm);

        $zone->loadMissing('franchise');
        $scope = array_filter([
            'zone_id' => $zone->id,
            'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id,
            'country_id' => $zone->franchise?->country_id,
        ]);

        return $this->rankAndLimit($candidates, $scope, $radiusKm, $limit);
    }

    /**
     * Phase 22.4 (Parcel) — the worker-aware dispatcher Phase B0.3's own
     * migration comment anticipated ("a future, separately-approved
     * worker-aware dispatcher can reuse this eligibility/ranking
     * primitive"). Reuses `RankingEngine`/`rankAndLimit()` completely
     * unchanged — including passing FieldWorker candidates under the same
     * `'provider'` array key `findCandidates()` uses, deliberately: a
     * direct re-read of RankingEngine found it keyed to `$c['provider']->
     * {priority,rating_avg,jobs_completed,user_id}` specifically (not a
     * generic "candidate" concept, despite its own docblock's broader
     * framing) — `FieldWorker` already carries the identical
     * `rating_avg`/`jobs_completed`/`user_id` columns Provider does (Phase
     * B0.1, built with exactly this kind of reuse in mind), and Eloquent
     * silently returns null for the one column it lacks (`priority`),
     * which the engine already treats as `?? 0`. This is genuine "same
     * engine" reuse (the mission's own instruction), not a coincidence of
     * matching names — verified against the real ranking logic, not
     * assumed. `findCandidates()` above is completely untouched.
     *
     * Eligibility, deliberately parallel to findCandidates()'s own list:
     *   - Same zone as the order's pickup address
     *   - Online right now, KYC approved, account active
     *   - Holds the 'parcel_rider' capability (App\Support\WorkerTypes —
     *     already seeded, not invented for this phase)
     *   - Not already offered this specific order
     *   - Not currently tied up on another active parcel order
     *   - Within the zone's dispatch radius of the pickup address
     *
     * @return Collection<int, array{provider: FieldWorker, distance_km: float}>
     */
    public function findWorkerCandidates(ParcelOrder $order, int $limit = 5): Collection
    {
        $order->loadMissing(['zone', 'pickupAddress', 'franchise']);

        if (! $order->zone || ! $order->pickupAddress) {
            return collect();
        }

        $alreadyOfferedWorkerIds = DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
            ->where('dispatchable_id', $order->id)
            ->where('notifiable_type', FieldWorker::class)
            ->pluck('notifiable_id');

        $busyWorkerIds = ParcelOrder::whereIn('status', ['assigned', 'worker_en_route_pickup', 'picked_up', 'en_route_dropoff'])
            ->whereNotNull('assigned_worker_id')
            ->pluck('assigned_worker_id');

        return $this->rankedFieldWorkerCandidates(
            capabilityType: 'parcel_rider',
            zoneId: $order->zone_id,
            excludeWorkerIds: $alreadyOfferedWorkerIds->merge($busyWorkerIds),
            pickupLat: (float) $order->pickupAddress->lat,
            pickupLng: (float) $order->pickupAddress->lng,
            radiusKm: $order->zone->default_dispatch_radius_km ?? 8,
            scope: array_filter([
                'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
                'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
            ]),
            limit: $limit,
        );
    }

    /**
     * Phase 22.6 (Taxi) — the second real consumer of the worker-dispatch
     * pattern `findWorkerCandidates()` (Parcel) established, and the
     * moment this codebase's own "generalize only where real repetition
     * exists" principle actually applies: extracted the truly-shared core
     * (`rankedFieldWorkerCandidates()` below) once a second real caller
     * existed to validate the abstraction against, rather than guessing it
     * ahead of time. What stayed different per vertical, deliberately:
     * the capability type, and — the part that genuinely can't be
     * generalized without a much larger parameter surface — what "busy"
     * means (a different table, a different status vocabulary, for each).
     *
     * @return Collection<int, array{provider: FieldWorker, distance_km: float}>
     */
    public function findTaxiDriverCandidates(TaxiRide $ride, int $limit = 5): Collection
    {
        $ride->loadMissing(['zone', 'pickupAddress', 'franchise']);

        if (! $ride->zone || ! $ride->pickupAddress) {
            return collect();
        }

        $alreadyOfferedWorkerIds = DispatchAttempt::where('dispatchable_type', TaxiRide::class)
            ->where('dispatchable_id', $ride->id)
            ->where('notifiable_type', FieldWorker::class)
            ->pluck('notifiable_id');

        $busyWorkerIds = TaxiRide::whereIn('status', ['assigned', 'driver_en_route', 'trip_started'])
            ->whereNotNull('assigned_worker_id')
            ->pluck('assigned_worker_id');

        return $this->rankedFieldWorkerCandidates(
            capabilityType: 'taxi_driver',
            zoneId: $ride->zone_id,
            excludeWorkerIds: $alreadyOfferedWorkerIds->merge($busyWorkerIds),
            pickupLat: (float) $ride->pickupAddress->lat,
            pickupLng: (float) $ride->pickupAddress->lng,
            radiusKm: $ride->zone->default_dispatch_radius_km ?? 8,
            scope: array_filter([
                'zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id,
                'city_id' => $ride->franchise?->city_id, 'country_id' => $ride->franchise?->country_id,
            ]),
            limit: $limit,
        );
    }

    /**
     * Phase 24 (Marketplace Foundation) — the third real consumer of the
     * shared `rankedFieldWorkerCandidates()` core, and the first whose
     * capability type is chosen at runtime (per the order's own `module`)
     * rather than fixed per vertical — a real, evidence-backed difference:
     * MarketplaceOrder genuinely serves four verticals, each with its own
     * `WorkerTypes` entry (`food_delivery_rider`/`grocery_delivery_rider`/
     * `commerce_delivery_rider`/`pharmacy_delivery_rider`). Pickup point is
     * the STORE's own lat/lng (the rider collects FROM the store), not a
     * customer address — the real point this codebase's existing
     * `pickupAddress`-shaped callers (Parcel/Taxi) don't have to make
     * explicit, since the shared helper already accepts raw floats.
     *
     * @return Collection<int, array{provider: FieldWorker, distance_km: float}>
     */
    public function findMarketplaceDeliveryRiderCandidates(MarketplaceOrder $order, int $limit = 5): Collection
    {
        $order->loadMissing(['zone', 'store', 'franchise']);

        if (! $order->zone || ! $order->store || $order->order_type !== 'delivery') {
            return collect();
        }

        $capabilityType = match ($order->module) {
            \App\Support\Modules::FOOD => 'food_delivery_rider',
            \App\Support\Modules::GROCERY => 'grocery_delivery_rider',
            \App\Support\Modules::PHARMACY => 'pharmacy_delivery_rider',
            default => 'commerce_delivery_rider',
        };

        $alreadyOfferedWorkerIds = DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)
            ->where('dispatchable_id', $order->id)
            ->where('notifiable_type', FieldWorker::class)
            ->pluck('notifiable_id');

        $busyWorkerIds = MarketplaceOrder::where('status', 'ready')
            ->where('order_type', 'delivery')
            ->whereNotNull('assigned_worker_id')
            ->pluck('assigned_worker_id');

        return $this->rankedFieldWorkerCandidates(
            capabilityType: $capabilityType,
            zoneId: $order->zone_id,
            excludeWorkerIds: $alreadyOfferedWorkerIds->merge($busyWorkerIds),
            pickupLat: (float) $order->store->lat,
            pickupLng: (float) $order->store->lng,
            radiusKm: $order->zone->default_dispatch_radius_km ?? 8,
            scope: array_filter([
                'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
                'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
            ]),
            limit: $limit,
        );
    }

    /**
     * The real shared core behind findWorkerCandidates()/
     * findTaxiDriverCandidates() — eligible-FieldWorker query, distance
     * filter, and ranking, parameterized by exactly the things that
     * genuinely differ (capability, exclusion set, pickup point) rather
     * than by vertical. Reuses RankingEngine/RankingConfigResolver
     * completely as-is, same as findWorkerCandidates()'s own original
     * "same engine" reasoning.
     */
    private function rankedFieldWorkerCandidates(
        string $capabilityType,
        int $zoneId,
        Collection $excludeWorkerIds,
        float $pickupLat,
        float $pickupLng,
        float $radiusKm,
        array $scope,
        int $limit
    ): Collection {
        $candidates = FieldWorker::query()
            ->where('zone_id', $zoneId)
            ->where('is_online', true)
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->whereHas('capabilities', fn ($q) => $q->where('capability_type', $capabilityType))
            ->whereNotIn('id', $excludeWorkerIds)
            ->get()
            ->map(fn (FieldWorker $worker) => [
                'provider' => $worker,
                'distance_km' => $this->haversineKm($pickupLat, $pickupLng, (float) $worker->current_lat, (float) $worker->current_lng),
            ])
            ->filter(fn ($c) => $c['distance_km'] <= $radiusKm);

        $config = $this->rankingConfigResolver->resolve('field_workers', $scope);

        return $this->rankingEngine
            ->rank(collect($candidates)->values(), $config, $radiusKm)
            ->take($limit)
            ->values();
    }

    /**
     * Phase B0.3: widened from private to protected — a visibility-only
     * change so a future, separately-approved worker-aware dispatcher can
     * reuse this eligibility/ranking primitive without duplicating it.
     * Behavior is completely unchanged.
     */
    protected function eligibleQuery(int $zoneId, int $categoryId)
    {
        return Provider::query()
            ->with(['subscriptions', 'user'])
            ->where('zone_id', $zoneId)
            ->where('is_online', true)
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng');
    }

    /** Phase B0.3: private -> protected, visibility-only (see eligibleQuery()'s note above). */
    protected function hasSkill(Provider $provider, int $categoryId): bool
    {
        $skills = $provider->skills ?? [];

        return in_array($categoryId, $skills, strict: false);
    }

    /** Phase B0.3: private -> protected, visibility-only (see eligibleQuery()'s note above). */
    protected function withDistance(Provider $provider, float $lat, float $lng): array
    {
        return [
            'provider' => $provider,
            'distance_km' => $this->haversineKm($lat, $lng, (float) $provider->current_lat, (float) $provider->current_lng),
        ];
    }

    /** Phase B0.3: private -> protected, visibility-only (see eligibleQuery()'s note above). */
    protected function rankAndLimit(Collection $candidates, array $scope, float $radiusKm, int $limit): Collection
    {
        $config = $this->rankingConfigResolver->resolve('providers', $scope);

        return $this->rankingEngine
            ->rank($candidates->values(), $config, $radiusKm)
            ->take($limit)
            ->values();
    }

    /**
     * Great-circle distance between two lat/lng points, in kilometers.
     */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 2);
    }
}
