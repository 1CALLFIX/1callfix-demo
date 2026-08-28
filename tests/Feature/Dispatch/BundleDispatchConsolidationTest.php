<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Jobs\BundleConsolidationJob;
use App\Jobs\ConsolidationOfferTimeoutJob;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use App\Services\DispatchService;
use App\Services\ProviderAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E4 — BundleConsolidationJob: after a bundle child is assigned, offer
 * the still-unassigned siblings to the SAME provider first, and fall back to
 * the unchanged ServiceMatchingJob on any eligibility miss. Assertions are
 * on real state (dispatch_attempts rows, provider_id / status changes),
 * not "the job was called".
 */
class BundleDispatchConsolidationTest extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    /**
     * @param  array<int, array{scheduled_at?: ?string, status?: string, service?: \App\Models\Service, address?: \App\Models\Address}>  $childSpecs
     * @return array{0: \App\Models\BookingBundle, 1: array<int, Booking>, 2: Provider, 3: array}
     */
    private function bundleWithFirstChildAssigned(array $childSpecs, ?Provider $provider = null): array
    {
        $ctx = $this->makeWorld();
        $ctx['customer'] = $this->makeCustomer();
        $ctx['address'] = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone']);
        $provider ??= $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);

        // default service for any spec that doesn't bring its own
        $default = $this->makeService($ctx['category'], 60);
        $childSpecs = array_map(function ($spec) use ($default) {
            $spec['service'] ??= $default;
            return $spec;
        }, $childSpecs);

        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, $childSpecs);

        // First child is already accepted by the provider.
        $rows[0]->update(['provider_id' => $provider->id, 'status' => 'assigned', 'start_otp' => '1111', 'completion_otp' => '2222']);
        $rows[0] = $rows[0]->fresh();

        return [$bundle, $rows, $provider, $ctx];
    }

    private function runConsolidation(Booking $assigned): void
    {
        (new BundleConsolidationJob($assigned->id))->handle(
            app(DispatchService::class),
            app(ProviderAvailabilityService::class),
        );
    }

    // ---------------------------------------------------------------

    public function test_sibling_is_offered_to_the_same_provider_and_can_accept(): void
    {
        Queue::fake();

        [, $rows, $x] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-01 10:00:00'], // A -> assigned to X, 10:00–11:00
            ['scheduled_at' => '2030-06-01 12:00:00'], // B -> still searching, 12:00–13:00 (no clash)
        ]);
        [$a, $b] = $rows;

        $this->runConsolidation($a);

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $b->id, 'provider_id' => $x->id, 'status' => 'notified',
        ]);
        Queue::assertPushed(ConsolidationOfferTimeoutJob::class);
        Queue::assertNotPushed(ServiceMatchingJob::class);

        // The same provider accepts the consolidation offer through the
        // normal acceptance path (its own E4 guard re-checks availability).
        $accepted = app(AcceptBookingAction::class)->execute($b->id, $x);
        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($x->id, $accepted->provider_id);
        $this->assertSame($x->id, $b->fresh()->provider_id);
    }

    public function test_skill_mismatch_falls_back_to_standard_dispatch(): void
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        // provider skilled for a DIFFERENT category
        $otherCat = $ctx['category']->replicate();
        $otherCat->slug = 'cat-other-'.uniqid();
        $otherCat->save();
        $x = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $otherCat->id);

        $default = $this->makeService($ctx['category'], 60);
        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, [
            ['scheduled_at' => '2030-06-02 10:00:00', 'service' => $default],
            ['scheduled_at' => '2030-06-02 12:00:00', 'service' => $default],
        ]);
        [$a, $b] = $rows;
        $a->update(['provider_id' => $x->id, 'status' => 'assigned']);

        $this->runConsolidation($a->fresh());

        $this->assertDatabaseMissing('dispatch_attempts', [
            'booking_id' => $b->id, 'provider_id' => $x->id,
        ]);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $b->id);
        Queue::assertNotPushed(ConsolidationOfferTimeoutJob::class);
    }

    public function test_radius_mismatch_falls_back_to_standard_dispatch(): void
    {
        Queue::fake();

        [$bundle, $rows, $x, $ctx] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-03 10:00:00'],
            ['scheduled_at' => '2030-06-03 12:00:00'],
        ]);
        [$a, $b] = $rows;

        // Move sibling B far outside the 8km dispatch radius of provider X (at 1.0,1.0).
        $farAddress = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone'], 2.0, 2.0);
        $b->update(['address_id' => $farAddress->id]);

        $this->runConsolidation($a);

        $this->assertDatabaseMissing('dispatch_attempts', ['booking_id' => $b->id, 'provider_id' => $x->id]);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $b->id);
    }

    public function test_availability_conflict_falls_back_to_standard_dispatch(): void
    {
        Queue::fake();

        [, $rows, $x] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-04 10:00:00'], // A assigned, 10:00–11:00
            ['scheduled_at' => '2030-06-04 10:30:00'], // B overlaps A -> X not free
        ]);
        [$a, $b] = $rows;

        $this->runConsolidation($a);

        $this->assertDatabaseMissing('dispatch_attempts', ['booking_id' => $b->id, 'provider_id' => $x->id]);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $b->id);
        Queue::assertNotPushed(ConsolidationOfferTimeoutJob::class);
    }

    public function test_offer_expiry_times_out_the_attempt_and_falls_back(): void
    {
        Queue::fake();

        [, $rows, $x] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-05 10:00:00'],
            ['scheduled_at' => '2030-06-05 12:00:00'],
        ]);
        [$a, $b] = $rows;

        $this->runConsolidation($a);
        $attempt = DispatchAttempt::where('booking_id', $b->id)->where('provider_id', $x->id)->firstOrFail();
        $this->assertSame('notified', $attempt->status);

        // Provider never accepts — the delayed timeout job fires.
        (new ConsolidationOfferTimeoutJob($b->id, $attempt->id))->handle();

        $this->assertSame('timeout', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->responded_at);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $b->id);
        $this->assertNull($b->fresh()->provider_id);
    }

    public function test_timeout_job_no_ops_when_provider_already_accepted(): void
    {
        Queue::fake();

        [, $rows, $x] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-06 10:00:00'],
            ['scheduled_at' => '2030-06-06 12:00:00'],
        ]);
        [$a, $b] = $rows;

        $this->runConsolidation($a);
        $attempt = DispatchAttempt::where('booking_id', $b->id)->where('provider_id', $x->id)->firstOrFail();

        app(AcceptBookingAction::class)->execute($b->id, $x);
        $this->assertSame('accepted', $attempt->fresh()->status);

        // Late timeout job must not disturb the accepted state.
        (new ConsolidationOfferTimeoutJob($b->id, $attempt->id))->handle();
        $this->assertSame('accepted', $attempt->fresh()->status);
        $this->assertSame($x->id, $b->fresh()->provider_id);
    }

    public function test_consolidation_disabled_does_only_standard_dispatch(): void
    {
        Queue::fake();
        Setting::set('dispatch.consolidation_enabled', '0');

        [, $rows, $x] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-07 10:00:00'],
            ['scheduled_at' => '2030-06-07 12:00:00'],
        ]);
        [$a, $b] = $rows;

        $this->runConsolidation($a);

        $this->assertDatabaseMissing('dispatch_attempts', ['booking_id' => $b->id, 'provider_id' => $x->id]);
        Queue::assertNotPushed(ConsolidationOfferTimeoutJob::class);
        // Disabled means "no consolidation attempt" — the sibling keeps whatever
        // standard dispatch E2 already queued for it; this job adds nothing.
        Queue::assertNotPushed(ServiceMatchingJob::class);
    }

    public function test_already_assigned_sibling_is_left_alone(): void
    {
        Queue::fake();

        [, $rows, $x, $ctx] = $this->bundleWithFirstChildAssigned([
            ['scheduled_at' => '2030-06-08 10:00:00'],
            ['scheduled_at' => '2030-06-08 12:00:00'],
        ]);
        [$a, $b] = $rows;

        $other = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.001, 1.001);
        $b->update(['provider_id' => $other->id, 'status' => 'assigned']);

        $this->runConsolidation($a);

        $this->assertDatabaseMissing('dispatch_attempts', ['booking_id' => $b->id, 'provider_id' => $x->id]);
        Queue::assertNothingPushed();
        $this->assertSame($other->id, $b->fresh()->provider_id);
    }
}
