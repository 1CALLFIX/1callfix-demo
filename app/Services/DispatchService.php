<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Provider;
use App\Models\Service;
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
     *   - Not already offered this specific booking (no dispatch_attempts row yet)
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

        $alreadyOfferedProviderIds = $booking->dispatchAttempts()
            ->pluck('provider_id');

        // 'on_hold' counts as busy too — a paused job (awaiting spares, customer
        // approval, or a provider-side red flag) still ties up that provider,
        // it's not a signal that they're free for new work.
        $busyProviderIds = Booking::whereIn('status', ['assigned', 'provider_en_route', 'in_progress', 'on_hold'])
            ->whereNotNull('provider_id')
            ->pluck('provider_id');

        $candidates = $this->eligibleQuery($booking->zone_id, $categoryId)
            ->whereNotIn('id', $alreadyOfferedProviderIds)
            ->whereNotIn('id', $busyProviderIds)
            ->get()
            ->filter(fn (Provider $provider) => $this->hasSkill($provider, $categoryId))
            ->map(fn (Provider $provider) => $this->withDistance($provider, (float) $booking->address->lat, (float) $booking->address->lng))
            ->filter(fn ($c) => $c['distance_km'] <= $radiusKm);

        $scope = array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);

        return $this->rankAndLimit($candidates, $scope, $radiusKm, $limit);
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
