<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Jobs\ServiceMatchingJob;
use App\Livewire\Provider\Jobs\Index as ProviderJobsIndex;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-012 — closes the offer-timeout-vs-acceptance race the
 * LAUNCH-012 audit found: AcceptBookingAction used to accept any attempt
 * whose status was still 'notified', with no regard for how old notified_at
 * actually was. Whether that status was still 'notified' depended entirely
 * on ServiceMatchingJob's own delayed next-round sweep (timeoutExpiredAttempts())
 * having already run — a best-effort queued job with no relationship to the
 * acceptance transaction. These tests pin down that AcceptBookingAction now
 * independently enforces the same freshness rule the sweep uses (same
 * Setting, same notified_at column, exact boundary parity), with a real row
 * lock closing the remaining unordered race between the two.
 */
class AcceptBookingOfferTimeoutTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function offer(int $bookingId, int $providerId, \DateTimeInterface $notifiedAt): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $bookingId,
            'provider_id' => $providerId,
            'status' => 'notified',
            'distance_km' => 1.0,
            'notified_at' => $notifiedAt,
        ]);
    }

    private function assertRejectedAsExpired(int $bookingId, $provider): void
    {
        try {
            app(AcceptBookingAction::class)->execute($bookingId, $provider);
            $this->fail('Expected the acceptance to be rejected for an expired offer.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'This job offer is no longer available (expired or already withdrawn).',
                $e->getMessage()
            );
        }
    }

    // ------------------------------------------------------------------
    // A. Fresh notified offer is accepted.
    // ------------------------------------------------------------------

    public function test_a_fresh_notified_offer_is_accepted(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeEligible($provider, $category->id);
        $this->offer($booking->id, $provider->id, now());

        $result = app(AcceptBookingAction::class)->execute($booking->id, $provider);

        $this->assertSame('assigned', $result->status);
        $this->assertSame($provider->id, $result->provider_id);
        $this->assertSame('accepted', DispatchAttempt::where('booking_id', $booking->id)->value('status'));
    }

    // ------------------------------------------------------------------
    // B. Clearly expired notified offer is rejected.
    // ------------------------------------------------------------------

    public function test_a_clearly_expired_notified_offer_is_rejected(): void
    {
        // Default in-code fallback is 25s (no settings row) — 26s is
        // unambiguously past it.
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeEligible($provider, $category->id);
        $attempt = $this->offer($booking->id, $provider->id, now()->subSeconds(26));

        $this->assertRejectedAsExpired($booking->id, $provider);

        $this->assertNull($booking->fresh()->provider_id);
        $this->assertSame('notified', $attempt->fresh()->status, 'A rejected-as-expired attempt is left untouched, not mutated.');
    }

    // ------------------------------------------------------------------
    // C. Stale already-rendered PWA offer is rejected through the real
    //    acceptance action (not merely the Livewire component's own
    //    shallow status-only pre-check).
    // ------------------------------------------------------------------

    public function test_a_stale_already_rendered_pwa_offer_is_rejected_through_the_real_acceptance_action(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeEligible($provider, $category->id);
        // Still 'notified' — a real page rendered while this was live would
        // pass the Livewire component's own hasLiveOffer pre-check (which
        // only checks status, not age), exactly like a stale-but-still-open
        // browser tab tapping Accept long after the offer should have expired.
        $attempt = $this->offer($booking->id, $provider->id, now()->subSeconds(26));
        $this->actingAs($provider->user);

        Livewire::test(ProviderJobsIndex::class)
            ->call('accept', $booking->id)
            ->assertSet('error', 'This job offer is no longer available (expired or already withdrawn).');

        $this->assertNull($booking->fresh()->provider_id);
        $this->assertSame('notified', $attempt->fresh()->status);
    }

    // ------------------------------------------------------------------
    // D. Configured timeout is respected instead of a hard-coded 25s.
    // ------------------------------------------------------------------

    public function test_configured_timeout_is_respected_instead_of_hardcoded_25_seconds(): void
    {
        Setting::set('dispatch.offer_timeout_seconds', '50');

        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeEligible($provider, $category->id);
        // 30s old: already past the 25s default, but comfortably inside a
        // configured 50s window — would incorrectly reject under a
        // hard-coded 25s check.
        $this->offer($booking->id, $provider->id, now()->subSeconds(30));

        $result = app(AcceptBookingAction::class)->execute($booking->id, $provider);

        $this->assertSame('assigned', $result->status);
    }

    // ------------------------------------------------------------------
    // E. Boundary behavior is deterministic — exact parity with
    //    ServiceMatchingJob::timeoutExpiredAttempts()'s own
    //    `notified_at <= now()->subSeconds(offerTimeoutSeconds())` rule.
    // ------------------------------------------------------------------

    public function test_boundary_behavior_is_deterministic(): void
    {
        Setting::set('dispatch.offer_timeout_seconds', '25');
        $frozenNow = Carbon::parse('2026-01-01 12:00:00');
        Carbon::setTestNow($frozenNow);

        // Exactly at the boundary (age === 25s) — the sweep's own `<=` rule
        // would time this out, so acceptance must reject it too.
        ['booking' => $atBoundary, 'provider' => $providerAtBoundary, 'category' => $categoryAtBoundary] = $this->makeBookingScenario('searching_provider');
        $providerAtBoundary = $this->makeEligible($providerAtBoundary, $categoryAtBoundary->id);
        $this->offer($atBoundary->id, $providerAtBoundary->id, $frozenNow->copy()->subSeconds(25));

        $this->assertRejectedAsExpired($atBoundary->id, $providerAtBoundary);

        // 1 second inside the window (age === 24s) — must still succeed.
        ['booking' => $justInside, 'provider' => $providerJustInside, 'category' => $categoryJustInside] = $this->makeBookingScenario('searching_provider');
        $providerJustInside = $this->makeEligible($providerJustInside, $categoryJustInside->id);
        $this->offer($justInside->id, $providerJustInside->id, $frozenNow->copy()->subSeconds(24));

        $result = app(AcceptBookingAction::class)->execute($justInside->id, $providerJustInside);
        $this->assertSame('assigned', $result->status);
    }

    // ------------------------------------------------------------------
    // F. The timeout sweep and acceptance cannot produce an invalid stale
    //    acceptance, regardless of which one a caller exercises first.
    // ------------------------------------------------------------------

    public function test_timeout_sweep_and_acceptance_cannot_produce_an_invalid_stale_acceptance(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeEligible($provider, $category->id);
        $attempt = $this->offer($booking->id, $provider->id, now()->subSeconds(26));

        // Acceptance attempted first, before the sweep has ever run: must
        // reject, and must not mark the attempt anything other than what it
        // already was.
        $this->assertRejectedAsExpired($booking->id, $provider);
        $this->assertSame('notified', $attempt->fresh()->status);
        $this->assertNull($booking->fresh()->provider_id);

        // The real sweep now runs (the next dispatch round) and correctly
        // closes the same attempt out — independent of, and consistent
        // with, the rejection above.
        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        $this->assertSame('timeout', $attempt->fresh()->status);
        $this->assertNull($booking->fresh()->provider_id, 'Neither path ever produced an invalid stale acceptance.');
    }

    // ------------------------------------------------------------------
    // G. Existing concurrent multi-provider acceptance still permits only
    //    one assignment (no regression from the new freshness/lock check).
    // ------------------------------------------------------------------

    public function test_concurrent_multi_provider_acceptance_still_permits_only_one_assignment(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'franchise' => $franchise, 'zone' => $zone, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $providerA = $this->makeEligible($providerA, $category->id);
        $providerB = $this->makeEligible($this->makeProviderIn($franchise, $zone), $category->id, lng: 1.001);

        $this->offer($booking->id, $providerA->id, now());
        $this->offer($booking->id, $providerB->id, now());

        app(AcceptBookingAction::class)->execute($booking->id, $providerA);

        try {
            app(AcceptBookingAction::class)->execute($booking->id, $providerB);
            $this->fail('Expected the second provider to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been assigned', $e->getMessage());
        }

        $this->assertSame($providerA->id, $booking->fresh()->provider_id);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'accepted')->count());
    }

    // ------------------------------------------------------------------
    // H. Expired acceptance produces no partial writes.
    // ------------------------------------------------------------------

    public function test_expired_acceptance_produces_no_partial_writes(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeEligible($provider, $category->id);
        $this->offer($booking->id, $provider->id, now()->subSeconds(26));

        $this->assertRejectedAsExpired($booking->id, $provider);

        $fresh = $booking->fresh();
        $this->assertNull($fresh->provider_id);
        $this->assertSame('searching_provider', $fresh->status);
        $this->assertNull($fresh->start_otp);
        $this->assertNull($fresh->completion_otp);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->count(), 'No extra dispatch_attempts row was created.');
        $this->assertSame(0, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'accepted')->count());
    }
}
