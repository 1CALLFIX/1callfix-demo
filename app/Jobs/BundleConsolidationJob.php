<?php

namespace App\Jobs;

use App\Events\NewJobOffered;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use App\Services\DispatchService;
use App\Services\ProviderAvailabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase E4 (Bundle Dispatch Consolidation). Runs right after a bundle child
 * booking has been successfully assigned to a provider (queued by
 * AcceptBookingAction, after commit). For every SIBLING child in the same
 * bundle that is still waiting for a provider, it tries the just-assigned
 * provider first — and only if every existing eligibility rule still passes:
 *
 *   1. consolidation is enabled for this scope
 *   2. the provider is still active / online / KYC-approved, in-zone,
 *      skilled for the sibling's service category, and within the zone's
 *      dispatch radius of the sibling's address
 *      (DispatchService::providerEligibleForBooking — the same helpers a
 *      normal dispatch round uses)
 *   3. the provider is free for the sibling's scheduled `[start, end)`
 *      window (ProviderAvailabilityService)
 *   4. the sibling is still genuinely dispatchable
 *
 * When all pass, it creates the SAME kind of `dispatch_attempts` row a
 * normal round creates (status `notified`) and broadcasts the SAME
 * `NewJobOffered` event, then hands expiry + fallback to
 * ConsolidationOfferTimeoutJob. Acceptance itself still goes through the
 * unchanged AcceptBookingAction (whose own E4 availability guard performs
 * the final race-safe recheck) — this is NOT a second acceptance path.
 *
 * When any check fails, the sibling is pushed straight into the existing
 * standard dispatch (ServiceMatchingJob) — consolidation never strands a
 * sibling, and it never changes RankingEngine or the Booking FSM.
 */
class BundleConsolidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Sibling statuses that mean "still waiting for a provider, safe to (re)dispatch". */
    private const DISPATCHABLE_STATUSES = ['pending', 'searching_provider'];

    public function __construct(
        public int $assignedBookingId,
    ) {
    }

    public function handle(
        DispatchService $dispatchService,
        ProviderAvailabilityService $availability,
    ): void {
        $assigned = Booking::with(['provider', 'bundle'])->find($this->assignedBookingId);

        if (! $assigned || ! $assigned->booking_bundle_id || ! $assigned->provider_id || ! $assigned->provider) {
            return;
        }

        $provider = $assigned->provider;

        $scope = array_filter([
            'zone_id' => $assigned->zone_id,
            'franchise_id' => $assigned->franchise_id,
        ]);

        if (Setting::get('dispatch.consolidation_enabled', '1', $scope) !== '1') {
            return;
        }

        $offerTimeoutSeconds = max(1, (int) Setting::get('dispatch.consolidation_offer_timeout_seconds', '5', $scope));

        $siblings = Booking::with(['service', 'address'])
            ->where('booking_bundle_id', $assigned->booking_bundle_id)
            ->where('id', '!=', $assigned->id)
            ->whereNull('provider_id')
            ->whereIn('status', self::DISPATCHABLE_STATUSES)
            ->get();

        foreach ($siblings as $sibling) {
            if ($this->providerCanTakeSibling($dispatchService, $availability, $provider, $sibling)) {
                $this->offerToProvider($dispatchService, $provider, $sibling, $offerTimeoutSeconds);
            } else {
                // No consolidation possible for this sibling — let it go
                // through the normal dispatch engine, unchanged.
                ServiceMatchingJob::dispatch($sibling->id);
            }
        }
    }

    private function providerCanTakeSibling(
        DispatchService $dispatchService,
        ProviderAvailabilityService $availability,
        Provider $provider,
        Booking $sibling,
    ): bool {
        if ($sibling->scheduled_at === null || ! $sibling->service) {
            return false;
        }

        if (! $dispatchService->providerEligibleForBooking($provider, $sibling)) {
            return false;
        }

        return $availability->isAvailableAt(
            $provider,
            $sibling->scheduled_at,
            (int) ($sibling->service->duration_estimate_mins ?? 0),
            $sibling->id,
        );
    }

    private function offerToProvider(
        DispatchService $dispatchService,
        Provider $provider,
        Booking $sibling,
        int $offerTimeoutSeconds,
    ): void {
        $distanceKm = null;

        if ($sibling->address && $provider->current_lat !== null && $provider->current_lng !== null) {
            $distanceKm = $dispatchService->haversineKm(
                (float) $sibling->address->lat,
                (float) $sibling->address->lng,
                (float) $provider->current_lat,
                (float) $provider->current_lng,
            );
        }

        $attempt = DispatchAttempt::create([
            'booking_id' => $sibling->id,
            'provider_id' => $provider->id,
            'status' => 'notified',
            'distance_km' => $distanceKm,
            'notified_at' => now(),
        ]);

        event(new NewJobOffered($sibling, $attempt));

        Log::info("BundleConsolidationJob: offered bundle sibling booking [{$sibling->id}] to provider [{$provider->id}] (from assigned booking [{$this->assignedBookingId}]).");

        ConsolidationOfferTimeoutJob::dispatch($sibling->id, $attempt->id)
            ->delay(now()->addSeconds($offerTimeoutSeconds));
    }
}
