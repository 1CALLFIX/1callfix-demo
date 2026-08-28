<?php

namespace Tests\Feature\BookingBundle;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Actions\StartBookingAction;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E5 — a bundle child runs its OWN accept -> start -> complete ->
 * settle lifecycle. One child completing must not drag the whole bundle to
 * "completed"; the bundle's derived cross-child status (E1) is the single
 * source of truth and only reads "completed" once every child is terminal.
 *
 * This is the required E5 end-to-end: E2 create -> E3 (wallet) pay ->
 * per-child dispatch -> provider accept -> start OTP -> completion OTP ->
 * settlement -> receipt, proven for each child independently. Only the
 * queue is faked (same boundary BundleE4IntegrationTest draws).
 */
class BundleChildCompletionE5Test extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    /** Take a freshly-created bundle child all the way to completed via the real Actions. */
    private function runChildToCompletion(Booking $child, \App\Models\Provider $provider): Booking
    {
        Booking::whereKey($child->id)->update(['status' => 'searching_provider']);
        $this->offer($child->fresh(), $provider);

        $accepted = app(AcceptBookingAction::class)->execute($child->id, $provider);
        $this->assertSame('assigned', $accepted->status);

        app(StartBookingAction::class)->execute($child->id, $accepted->start_otp);
        $this->assertSame('in_progress', $child->fresh()->status);

        return app(CompleteBookingAction::class)->execute($child->id, $provider, $accepted->fresh()->completion_otp);
    }

    private function makePaidBundle(): array
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        Wallet::create(['user_id' => $customer->id, 'balance' => 100000]);

        $serviceA = $this->makeService($ctx['category'], 60, ['base_price' => 400]);
        $serviceB = $this->makeService($ctx['category'], 60, ['base_price' => 600]);
        $providerA = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);
        $providerB = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);

        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $customer->id,
            'payment_method' => 'wallet',
            'idempotency_key' => null,
            'request_fingerprint' => 'e5-bundle-fingerprint',
            'children' => [
                ['service_id' => $serviceA->id, 'franchise_id' => $ctx['franchise']->id, 'zone_id' => $ctx['zone']->id, 'address_id' => $address->id, 'scheduled_at' => '2030-09-01 09:00:00'],
                ['service_id' => $serviceB->id, 'franchise_id' => $ctx['franchise']->id, 'zone_id' => $ctx['zone']->id, 'address_id' => $address->id, 'scheduled_at' => '2030-09-02 09:00:00'],
            ],
        ]);

        $bundle->refresh()->loadMissing('children');
        $a = $bundle->children->firstWhere('service_id', $serviceA->id);
        $b = $bundle->children->firstWhere('service_id', $serviceB->id);

        return compact('bundle', 'a', 'b', 'providerA', 'providerB');
    }

    public function test_one_child_completes_and_settles_while_the_sibling_stays_active(): void
    {
        ['bundle' => $bundle, 'a' => $a, 'b' => $b, 'providerA' => $providerA] = $this->makePaidBundle();

        $completedA = $this->runChildToCompletion($a, $providerA);

        $this->assertSame('completed', $completedA->status);
        $this->assertSame(1, Commission::where('booking_id', $a->id)->count());
        $this->assertSame(0, Commission::where('booking_id', $b->id)->count(), 'B must not settle while it is still active.');
        $this->assertContains($b->fresh()->status, ['pending', 'searching_provider'], 'B keeps its own lifecycle.');

        // The bundle is NOT completed — one child is still outstanding.
        $this->assertSame('partially_completed', $bundle->fresh()->derivedStatus());
        $this->assertSame('active', $bundle->fresh()->status, 'The stored latch is untouched by a single child completion.');
    }

    public function test_the_bundle_derives_completed_only_once_every_child_is_terminal(): void
    {
        ['bundle' => $bundle, 'a' => $a, 'b' => $b, 'providerA' => $providerA, 'providerB' => $providerB] = $this->makePaidBundle();

        $this->runChildToCompletion($a, $providerA);
        $this->assertSame('partially_completed', $bundle->fresh()->derivedStatus());

        $this->runChildToCompletion($b, $providerB);

        $this->assertSame('completed', $bundle->fresh()->derivedStatus());
        $this->assertSame(2, Commission::whereIn('booking_id', [$a->id, $b->id])->count());
    }

    public function test_each_child_settlement_is_independent_and_credited_to_its_own_provider(): void
    {
        ['a' => $a, 'b' => $b, 'providerA' => $providerA, 'providerB' => $providerB] = $this->makePaidBundle();

        $this->runChildToCompletion($a, $providerA);
        $this->runChildToCompletion($b, $providerB);

        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$a->id}:provider-earning")->count());
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$b->id}:provider-earning")->count());
        $this->assertGreaterThan(0, (float) $providerA->user->wallet->fresh()->balance);
        $this->assertGreaterThan(0, (float) $providerB->user->wallet->fresh()->balance);
    }

    public function test_the_bundle_receipt_is_materialised_once_regardless_of_how_many_children_complete(): void
    {
        ['bundle' => $bundle, 'a' => $a, 'b' => $b, 'providerA' => $providerA, 'providerB' => $providerB] = $this->makePaidBundle();

        $this->runChildToCompletion($a, $providerA);
        $this->runChildToCompletion($b, $providerB);

        // Children carry no Payment of their own (E3 keeps ONE per bundle),
        // so completion materialises the bundle's aggregate receipt — once.
        $bundlePayment = Payment::where('booking_bundle_id', $bundle->id)->where('purpose', 'booking_bundle')->firstOrFail();
        $this->assertSame(1, GeneratedDocument::where('documentable_id', $bundlePayment->id)->where('type', 'receipt')->count());
        $this->assertSame(1, GeneratedDocument::count());
    }

    public function test_completing_one_child_twice_does_not_double_settle_or_re_derive_the_bundle(): void
    {
        ['bundle' => $bundle, 'a' => $a, 'providerA' => $providerA] = $this->makePaidBundle();

        $completedA = $this->runChildToCompletion($a, $providerA);
        $otpUsed = $completedA->completion_otp; // already null — consumed

        try {
            app(CompleteBookingAction::class)->execute($a->id, $providerA, (string) $otpUsed);
            $this->fail('Expected the replayed child completion to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be completed', $e->getMessage());
        }

        $this->assertSame(1, Commission::where('booking_id', $a->id)->count());
        $this->assertSame('partially_completed', $bundle->fresh()->derivedStatus());
    }
}
