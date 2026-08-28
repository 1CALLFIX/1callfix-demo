<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Jobs\BundleConsolidationJob;
use App\Jobs\ServiceMatchingJob;
use App\Services\DispatchService;
use App\Services\ProviderAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E4 §19 — a 3-child bundle (A/B/C). A is assigned to X; B consolidates
 * to X; C cannot (outside X's dispatch radius) and falls back to standard
 * dispatch. Each sibling is evaluated independently — one falling back must
 * not block the other from consolidating.
 */
class BundleConsolidationMultiChildTest extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    public function test_three_child_bundle_consolidates_one_sibling_and_falls_back_the_other(): void
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        $ctx['customer'] = $this->makeCustomer();
        $ctx['address'] = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone']);
        $service = $this->makeService($ctx['category'], 60);
        $x = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.0, 1.0);

        $farAddress = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone'], 2.0, 2.0);

        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, [
            ['service' => $service, 'scheduled_at' => '2030-07-01 09:00:00'],                          // A
            ['service' => $service, 'scheduled_at' => '2030-07-01 11:00:00'],                          // B - consolidates
            ['service' => $service, 'scheduled_at' => '2030-07-01 13:00:00', 'address' => $farAddress], // C - too far for X
        ]);
        [$a, $b, $c] = $rows;

        $a->update(['provider_id' => $x->id, 'status' => 'assigned', 'start_otp' => '1111', 'completion_otp' => '2222']);

        (new BundleConsolidationJob($a->fresh()->id))->handle(
            app(DispatchService::class),
            app(ProviderAvailabilityService::class),
        );

        // B: consolidation offer to X.
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $b->id, 'provider_id' => $x->id, 'status' => 'notified',
        ]);

        // C: no offer to X, standard dispatch queued instead.
        $this->assertDatabaseMissing('dispatch_attempts', ['booking_id' => $c->id, 'provider_id' => $x->id]);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $c->id);
        Queue::assertNotPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $b->id);

        // B is genuinely assignable to X through the normal path.
        $accepted = app(AcceptBookingAction::class)->execute($b->id, $x);
        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($x->id, $accepted->provider_id);

        // C remains unassigned and free for standard dispatch to resolve.
        $this->assertNull($c->fresh()->provider_id);
    }
}
