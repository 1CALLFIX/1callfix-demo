<?php

namespace Tests\Feature\Dispatch;

use App\Jobs\ServiceMatchingJob;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-008 — the fix for the LAUNCH-007 audit's RED finding:
 * DispatchService::eligibleQuery()/providerEligibleForBooking() never read
 * location_updated_at, so a provider's coordinates were trusted regardless
 * of how old they were. This proves the freshness gate now added to both
 * methods, using the SAME setting (provider.location_stale_after_minutes)
 * BuildsProviderEligibility's advisory dashboard panel already reads — one
 * threshold, read dynamically here rather than hardcoded, so this suite
 * keeps proving the real configured behavior even if the default changes.
 */
class ProviderLocationFreshnessTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function staleAfterMinutes(): int
    {
        return max(1, (int) Setting::get('provider.location_stale_after_minutes', '30'));
    }

    /** A real, otherwise-fully-eligible dispatch scenario; the provider's location is set by the caller. */
    private function eligibleScenario(?\DateTimeInterface $locationUpdatedAt, ?float $lat = 1.001, ?float $lng = 1.001): array
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['provider']->update([
            'skills' => [$scenario['category']->id],
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => $locationUpdatedAt,
        ]);
        $scenario['provider'] = $scenario['provider']->fresh();

        return $scenario;
    }

    // ============================== 1-5: boundary matrix ==============================

    public function test_fresh_location_is_dispatchable(): void
    {
        $s = $this->eligibleScenario(now());

        $this->assertTrue(app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']));
        $candidateIds = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id');
        $this->assertTrue($candidateIds->contains($s['provider']->id));
    }

    public function test_location_just_inside_the_configured_threshold_is_dispatchable(): void
    {
        $threshold = $this->staleAfterMinutes();
        // One minute short of the boundary -- safely inside on any threshold >= 2,
        // and still meaningfully "old" for the default 30-minute setting.
        $s = $this->eligibleScenario(now()->subMinutes(max(1, $threshold - 1)));

        $this->assertTrue(app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']));
        $candidateIds = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id');
        $this->assertTrue($candidateIds->contains($s['provider']->id));
    }

    public function test_location_just_outside_the_configured_threshold_is_not_dispatchable(): void
    {
        $threshold = $this->staleAfterMinutes();
        $s = $this->eligibleScenario(now()->subMinutes($threshold + 1));

        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']));
        $candidateIds = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id');
        $this->assertFalse($candidateIds->contains($s['provider']->id));
    }

    public function test_null_location_updated_at_is_not_dispatchable_even_with_real_coordinates(): void
    {
        $s = $this->eligibleScenario(null);

        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']));
        $candidateIds = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id');
        $this->assertFalse($candidateIds->contains($s['provider']->id));
    }

    public function test_null_coordinates_remain_not_dispatchable(): void
    {
        $s = $this->eligibleScenario(now(), lat: null, lng: null);

        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']));
        $candidateIds = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id');
        $this->assertFalse($candidateIds->contains($s['provider']->id));
    }

    // ============================== 6: existing rules unchanged ==============================

    public function test_existing_online_active_kyc_skill_zone_distance_rules_still_apply_to_a_fresh_provider(): void
    {
        // Each sub-case: otherwise-fresh provider, one existing rule violated.
        $offline = $this->eligibleScenario(now());
        $offline['provider']->update(['is_online' => false]);
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($offline['provider']->fresh(), $offline['booking']));

        $inactive = $this->eligibleScenario(now());
        $inactive['provider']->update(['is_active' => false]);
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($inactive['provider']->fresh(), $inactive['booking']));

        $unapprovedKyc = $this->eligibleScenario(now());
        $unapprovedKyc['provider']->update(['kyc_status' => 'pending']);
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($unapprovedKyc['provider']->fresh(), $unapprovedKyc['booking']));

        $noSkill = $this->eligibleScenario(now());
        $noSkill['provider']->update(['skills' => []]);
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($noSkill['provider']->fresh(), $noSkill['booking']));

        $wrongZone = $this->eligibleScenario(now());
        [, , , $otherZone] = $this->makeFranchiseTree();
        $wrongZone['provider']->update(['zone_id' => $otherZone->id]);
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($wrongZone['provider']->fresh(), $wrongZone['booking']));

        $tooFar = $this->eligibleScenario(now(), lat: 50.0, lng: 50.0); // far outside the zone's radius
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($tooFar['provider']->fresh(), $tooFar['booking']));
    }

    // ============================== 7: query path and single-provider proof agree ==============================

    public function test_provider_eligible_for_booking_agrees_with_find_candidates_for_a_fresh_provider(): void
    {
        $s = $this->eligibleScenario(now());

        $singleProviderResult = app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']);
        $queryPathResult = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id')->contains($s['provider']->id);

        $this->assertTrue($singleProviderResult);
        $this->assertTrue($queryPathResult);
        $this->assertSame($singleProviderResult, $queryPathResult);
    }

    public function test_provider_eligible_for_booking_agrees_with_find_candidates_for_a_stale_provider(): void
    {
        $threshold = $this->staleAfterMinutes();
        $s = $this->eligibleScenario(now()->subMinutes($threshold + 5));

        $singleProviderResult = app(DispatchService::class)->providerEligibleForBooking($s['provider'], $s['booking']);
        $queryPathResult = app(DispatchService::class)->findCandidates($s['booking'], 5)->pluck('provider.id')->contains($s['provider']->id);

        $this->assertFalse($singleProviderResult);
        $this->assertFalse($queryPathResult);
        $this->assertSame($singleProviderResult, $queryPathResult);
    }

    // ============================== 8-9: real dispatch round via ServiceMatchingJob ==============================

    public function test_a_stale_provider_does_not_receive_a_new_service_offer(): void
    {
        $threshold = $this->staleAfterMinutes();
        $s = $this->eligibleScenario(now()->subMinutes($threshold + 5));

        (new ServiceMatchingJob($s['booking']->id, 1))->handle(app(DispatchService::class));

        $this->assertDatabaseMissing('dispatch_attempts', [
            'booking_id' => $s['booking']->id,
            'provider_id' => $s['provider']->id,
        ]);
    }

    public function test_a_fresh_provider_remains_eligible_and_receives_the_offer(): void
    {
        $s = $this->eligibleScenario(now());

        (new ServiceMatchingJob($s['booking']->id, 1))->handle(app(DispatchService::class));

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $s['booking']->id,
            'provider_id' => $s['provider']->id,
            'status' => 'notified',
        ]);
    }

    // ============================== 10: is_active stays independent of location freshness ==============================

    public function test_provider_is_active_is_untouched_by_and_independent_of_location_freshness(): void
    {
        $threshold = $this->staleAfterMinutes();

        // A stale-but-active provider is excluded for freshness, not for is_active.
        $stale = $this->eligibleScenario(now()->subMinutes($threshold + 5));
        $this->assertTrue((bool) $stale['provider']->fresh()->is_active, 'is_active must not be touched by the freshness check.');
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($stale['provider'], $stale['booking']));

        // A fresh-but-inactive provider is excluded for is_active, not for freshness -- the two remain separate gates.
        $inactiveButFresh = $this->eligibleScenario(now());
        $inactiveButFresh['provider']->update(['is_active' => false]);
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($inactiveButFresh['provider']->fresh(), $inactiveButFresh['booking']));
        // Restoring is_active alone (location untouched) makes them eligible again -- proves freshness was never the blocker here.
        $inactiveButFresh['provider']->update(['is_active' => true]);
        $this->assertTrue(app(DispatchService::class)->providerEligibleForBooking($inactiveButFresh['provider']->fresh(), $inactiveButFresh['booking']));
    }
}
