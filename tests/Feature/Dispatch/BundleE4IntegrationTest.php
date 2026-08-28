<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Jobs\BundleConsolidationJob;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Wallet;
use App\Services\DispatchService;
use App\Services\ProviderAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E4 §22 — end-to-end: E2 bundle creation + E3 (wallet) payment →
 * per-child dispatch → provider X accepts child A → BundleConsolidationJob
 * offers child B to X (skill + radius + availability all pass) → X accepts B
 * → A and B both assigned to X. No external services; queue faked, same as
 * the E3 payment test.
 */
class BundleE4IntegrationTest extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    public function test_paid_bundle_dispatches_then_consolidates_both_children_onto_one_provider(): void
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        Wallet::create(['user_id' => $customer->id, 'balance' => 100000]);

        $serviceA = $this->makeService($ctx['category'], 60, ['base_price' => 400]);
        $serviceB = $this->makeService($ctx['category'], 60, ['base_price' => 600]);
        $x = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.0, 1.0);

        // ---- E2 create + E3 wallet payment (one aggregate debit) ----
        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $customer->id,
            'payment_method' => 'wallet',
            'idempotency_key' => null,
            'request_fingerprint' => 'e4-integration-fingerprint',
            'children' => [
                [
                    'service_id' => $serviceA->id, 'franchise_id' => $ctx['franchise']->id,
                    'zone_id' => $ctx['zone']->id, 'address_id' => $address->id,
                    'scheduled_at' => '2030-08-01 09:00:00',
                ],
                [
                    'service_id' => $serviceB->id, 'franchise_id' => $ctx['franchise']->id,
                    'zone_id' => $ctx['zone']->id, 'address_id' => $address->id,
                    'scheduled_at' => '2030-08-01 11:00:00',
                ],
            ],
        ]);

        $bundle->refresh()->loadMissing('children');
        $this->assertSame('paid', $bundle->payment_status);
        $this->assertCount(2, $bundle->children);
        $this->assertSame(1000.0, (float) $bundle->total_price_quoted);

        // Every child dispatched through the existing per-booking engine.
        Queue::assertPushed(ServiceMatchingJob::class, 2);
        $bundle->children->each(fn (Booking $c) => $this->assertSame('paid', $c->payment_status));

        // ---- dispatch reaches the point where X accepts child A ----
        $a = $bundle->children->firstWhere('service_id', $serviceA->id);
        $b = $bundle->children->firstWhere('service_id', $serviceB->id);
        Booking::whereIn('id', [$a->id, $b->id])->update(['status' => 'searching_provider']);
        $this->offer($a->fresh(), $x);

        $acceptedA = app(AcceptBookingAction::class)->execute($a->id, $x);
        $this->assertSame('assigned', $acceptedA->status);
        $this->assertSame($x->id, $acceptedA->provider_id);

        // AcceptBookingAction queued consolidation for the bundle child.
        Queue::assertPushed(BundleConsolidationJob::class, fn ($job) => $job->assignedBookingId === $a->id);

        // ---- run consolidation: B offered to X, X accepts ----
        (new BundleConsolidationJob($a->id))->handle(
            app(DispatchService::class),
            app(ProviderAvailabilityService::class),
        );

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $b->id, 'provider_id' => $x->id, 'status' => 'notified',
        ]);

        $acceptedB = app(AcceptBookingAction::class)->execute($b->id, $x);
        $this->assertSame('assigned', $acceptedB->status);
        $this->assertSame($x->id, $acceptedB->provider_id);

        // ---- both children on the one provider ----
        $this->assertSame(2, Booking::where('booking_bundle_id', $bundle->id)
            ->where('provider_id', $x->id)->where('status', 'assigned')->count());
    }
}
