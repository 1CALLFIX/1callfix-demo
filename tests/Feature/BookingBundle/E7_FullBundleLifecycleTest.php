<?php

namespace Tests\Feature\BookingBundle;

use App\Actions\AcceptBookingAction;
use App\Actions\StartBookingAction;
use App\Jobs\BundleConsolidationJob;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\DispatchService;
use App\Services\ProviderAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E7 — the one true end-to-end walk of a multi-service bundle, from
 * customer creation to a mixed completed/cancelled terminal state, with the
 * money reconciled at the end. No internal Action is mocked: bundle
 * creation goes through the real HTTP endpoint, payment through the real
 * WalletService, dispatch/consolidation through the real jobs, acceptance /
 * start / completion / cancellation through the real Actions. Only the
 * queue is faked (the same boundary BundleE4IntegrationTest /
 * BundleChildCompletionE5Test already draw), so the after-commit jobs can
 * be asserted on and then run by hand in order.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * The two gaps this scenario originally pinned as KNOWN-BROKEN were closed
 * by Phase E5.1; steps 9–11 now assert the corrected behaviour:
 *
 *   Gap 1 — step 9 cancels the last active child through the E5.1 bundle-
 *   cancel endpoint (POST /api/booking-bundles/{id}/cancel). The ONE shared
 *   bundle Payment (E3) is reconciled: retained = Σ price_quoted of the
 *   delivered children + Σ cancellation fee of the cancelled ones; the
 *   remainder is refunded to the wallet, once. A bundle child still never
 *   gets its own Payment row.
 *
 *   Gap 2 — step 10 asserts the STORED BookingBundle.status column
 *   (re-fetched fresh) is now latched to 'completed', not just
 *   derivedStatus().
 * ─────────────────────────────────────────────────────────────────────────
 */
class E7_FullBundleLifecycleTest extends TestCase
{
    use BundleConsolidationHelpers;
    use RefreshDatabase;

    /**
     * Start -> complete an already-assigned child through the real Actions +
     * the real HTTP complete endpoint, using the OTPs minted at acceptance.
     */
    private function startAndComplete(Booking $child, Provider $provider, string $startOtp, string $completionOtp): Booking
    {
        $this->assertSame('assigned', $child->fresh()->status);

        app(StartBookingAction::class)->execute($child->id, $startOtp);
        $this->assertSame('in_progress', $child->fresh()->status);

        // completion through the real HTTP endpoint -> DispatchController -> CompleteBookingAction
        $this->actingAs($provider->user, 'sanctum')
            ->postJson("/api/bookings/{$child->id}/complete", ['otp' => $completionOtp])
            ->assertOk()
            ->assertJsonPath('booking.status', 'completed');

        return $child->fresh();
    }

    public function test_full_three_service_bundle_lifecycle_with_a_mixed_terminal_state_reconciles(): void
    {
        Queue::fake();

        // ── world: 3 priced services, franchise takes a 10% platform fee ──
        $ctx = $this->makeWorld();
        $ctx['franchise']->update(['platform_fee_percent' => 10, 'commission_model' => 'revenue_share', 'commission_value' => 0]);

        // widen the admin-configurable scheduling window so the three children
        // can carry far-future, comfortably non-overlapping slots (the request
        // rule is nullable|after:now|before_or_equal:now()+booking.max_schedule_days_ahead).
        Setting::set('booking.max_schedule_days_ahead', '3650');

        $customer = $this->makeCustomer();
        $nearAddress = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone'], 1.0, 1.0);
        $farAddress = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone'], 2.0, 2.0);

        $openingBalance = 100000.0;
        Wallet::create(['user_id' => $customer->id, 'balance' => $openingBalance]);

        $svc1 = $this->makeService($ctx['category'], 60, ['base_price' => 400]);
        $svc2 = $this->makeService($ctx['category'], 60, ['base_price' => 600]);
        $svc3 = $this->makeService($ctx['category'], 60, ['base_price' => 500]);
        $bundleTotal = 1500.0; // 400 + 600 + 500, server-computed (no sale/override/membership)

        $providerA = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.0, 1.0);
        $providerB = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 2.0, 2.0);

        // ── 1. customer creates a 3-service bundle at 3 different times, wallet-funded (real HTTP) ──
        $this->actingAs($customer, 'sanctum')->postJson('/api/booking-bundles', [
            'payment_method' => 'wallet',
            'services' => [
                ['service_id' => $svc1->id, 'address_id' => $nearAddress->id, 'scheduled_at' => '2030-09-01 09:00:00'],
                ['service_id' => $svc2->id, 'address_id' => $nearAddress->id, 'scheduled_at' => '2030-09-01 13:00:00'],
                ['service_id' => $svc3->id, 'address_id' => $farAddress->id,  'scheduled_at' => '2030-09-02 09:00:00'],
            ],
        ])->assertStatus(201);

        $bundle = BookingBundle::firstOrFail();
        $bundle->loadMissing('children');
        $this->assertCount(3, $bundle->children);
        $this->assertEqualsWithDelta($bundleTotal, (float) $bundle->total_price_quoted, 0.001);

        $c1 = $bundle->children->firstWhere('service_id', $svc1->id);
        $c2 = $bundle->children->firstWhere('service_id', $svc2->id);
        $c3 = $bundle->children->firstWhere('service_id', $svc3->id);

        // ── 2. wallet debited atomically for the bundle total — exact balance ──
        $this->assertEqualsWithDelta($openingBalance - $bundleTotal, (float) $customer->wallet->fresh()->balance, 0.001);
        $debits = WalletTransaction::where('is_credit', false)->get();
        $this->assertCount(1, $debits, 'exactly ONE aggregate debit, never one per child');
        $this->assertEqualsWithDelta($bundleTotal, (float) $debits->first()->amount, 0.001);
        $bundlePayment = Payment::where('booking_bundle_id', $bundle->id)->where('purpose', 'booking_bundle')->firstOrFail();
        $this->assertSame('captured', $bundlePayment->status);
        $this->assertSame('paid', $bundle->fresh()->payment_status);
        $bundle->children->each(fn (Booking $c) => $this->assertSame('paid', $c->payment_status));

        // ── 3. dispatch fires for all three children ──
        Queue::assertPushed(ServiceMatchingJob::class, 3);
        foreach ([$c1, $c2, $c3] as $child) {
            Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $child->id);
        }

        // ── 4. provider A accepts child 1 -> consolidation job is dispatched for that assignment ──
        Booking::whereIn('id', [$c1->id, $c2->id, $c3->id])->update(['status' => 'searching_provider']);
        $this->offer($c1->fresh(), $providerA);
        $acceptedC1 = app(AcceptBookingAction::class)->execute($c1->id, $providerA);
        $this->assertSame('assigned', $acceptedC1->status);
        $this->assertSame($providerA->id, $acceptedC1->provider_id);

        Queue::assertPushed(
            BundleConsolidationJob::class,
            fn ($job) => $job->assignedBookingId === $c1->id,
        );

        // run the consolidation job by hand (queue is faked): it evaluates
        // BOTH still-unassigned siblings — child 2 (near, non-overlapping) and
        // child 3 (far).
        (new BundleConsolidationJob($c1->id))->handle(
            app(DispatchService::class),
            app(ProviderAvailabilityService::class),
        );

        // child 2: a real consolidation offer to provider A (skill + radius +
        // the [09:00,10:00) vs [13:00,14:00) availability check all passed).
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $c2->id, 'provider_id' => $providerA->id, 'status' => 'notified',
        ]);
        // child 3: NO consolidation offer to provider A (its far address is
        // outside provider A's dispatch radius) -> explicit fallback to
        // standard dispatch.
        $this->assertDatabaseMissing('dispatch_attempts', [
            'booking_id' => $c3->id, 'provider_id' => $providerA->id,
        ]);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $c3->id);

        // ── 5. provider A accepts child 2 via the consolidated offer (same
        //       provider, different scheduled time — the availability guard in
        //       AcceptBookingAction let it through) ──
        $acceptedC2 = app(AcceptBookingAction::class)->execute($c2->id, $providerA);
        $this->assertSame('assigned', $acceptedC2->status);
        $this->assertSame($providerA->id, $acceptedC2->provider_id);
        $this->assertSame(
            2,
            Booking::where('booking_bundle_id', $bundle->id)->where('provider_id', $providerA->id)->count(),
            'provider A now holds two non-overlapping bundle children',
        );

        // ── 6. a DIFFERENT provider B accepts child 3 via the fallback path ──
        $this->offer($c3->fresh(), $providerB);
        $acceptedC3 = app(AcceptBookingAction::class)->execute($c3->id, $providerB);
        $this->assertSame('assigned', $acceptedC3->status);
        $this->assertSame($providerB->id, $acceptedC3->provider_id);

        // ── 7. complete child 1 -> partially_completed, commission to provider A only ──
        $this->startAndComplete($c1, $providerA, $acceptedC1->start_otp, $acceptedC1->completion_otp);
        $this->assertSame('completed', $c1->fresh()->status);
        $this->assertSame('partially_completed', $bundle->fresh()->derivedStatus());
        $this->assertSame(1, Commission::where('booking_id', $c1->id)->count());
        $this->assertSame(0, Commission::where('booking_id', $c2->id)->count());
        $this->assertSame(0, Commission::where('booking_id', $c3->id)->count());

        // ── 8. complete child 2 -> still partially_completed (child 3 active) ──
        $this->startAndComplete($c2, $providerA, $acceptedC2->start_otp, $acceptedC2->completion_otp);
        $this->assertSame('completed', $c2->fresh()->status);
        $this->assertSame('partially_completed', $bundle->fresh()->derivedStatus());
        $this->assertSame('assigned', $c3->fresh()->status, 'child 3 is untouched by the sibling completions');

        // ── 9. cancel the remaining active child via the bundle-cancel
        //       endpoint (Phase E5.1 — POST /api/booking-bundles/{id}/cancel;
        //       cancels every still-active child, here only child 3) ──
        $balanceBeforeCancel = (float) $customer->wallet->fresh()->balance;
        $c1PriceQuoted = (float) $c1->fresh()->price_quoted; // 400 — its share of the frozen bundle total
        $c2PriceQuoted = (float) $c2->fresh()->price_quoted; // 600
        $c1PriceFinal = (float) $c1->fresh()->price_final;
        $c2PriceFinal = (float) $c2->fresh()->price_final;

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed'); // stored latch: >=1 completed, rest terminal

        $c3 = $c3->fresh();
        $this->assertSame('cancelled', $c3->status);
        $this->assertNotNull($c3->cancellation_fee, 'a cancellation fee is computed for the child');
        $this->assertSame(0.0, (float) $c3->cancellation_fee, 'created inside the free window -> fee 0');

        // children 1 and 2 completely untouched by the child-3 cancellation
        $this->assertSame('completed', $c1->fresh()->status);
        $this->assertSame('completed', $c2->fresh()->status);
        $this->assertNull($c1->fresh()->cancellation_fee);
        $this->assertNull($c2->fresh()->cancellation_fee);

        // E5.1 GAP 1 CLOSED: the ONE shared bundle Payment is reconciled —
        // retained = c1.price_quoted + c2.price_quoted (delivered) + c3 fee (0);
        // refunded = 1500 - 1000 - 0 = 500, credited once to the wallet.
        $expectedRefund = $bundleTotal - $c1PriceQuoted - $c2PriceQuoted; // 500
        $this->assertEqualsWithDelta(
            $balanceBeforeCancel + $expectedRefund,
            (float) $customer->wallet->fresh()->balance,
            0.001,
        );
        $refundTxns = WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->get();
        $this->assertCount(1, $refundTxns, 'exactly one bundle refund credit');
        $this->assertEqualsWithDelta($expectedRefund, (float) $refundTxns->first()->amount, 0.001);
        $this->assertSame(
            0,
            Payment::where('booking_id', $c3->id)->count(),
            'a bundle child still never gets its own Payment row',
        );
        $bundlePayment->refresh();
        $this->assertSame('partially_refunded', $bundlePayment->status);
        $this->assertEqualsWithDelta($expectedRefund, (float) $bundlePayment->refunded_amount, 0.001);
        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->count(), 'still exactly one bundle Payment');

        // ── 10. final bundle state ──
        // E5.1 GAP 2 CLOSED: the STORED status column (re-fetched fresh, not
        // the in-memory instance, not derivedStatus()) is now latched.
        $freshBundle = BookingBundle::findOrFail($bundle->id);
        $this->assertSame('completed', $freshBundle->status);
        $this->assertSame('completed', $freshBundle->derivedStatus());
        $this->assertSame('partially_refunded', $freshBundle->payment_status);

        // ── 11. money-math reconciliation ──
        // Only children 1 and 2 ever settled. Franchise: 10% platform fee,
        // 0% revenue share, no franchise owner -> provider keeps price_final
        // minus the platform cut.
        $comm1 = Commission::where('booking_id', $c1->id)->firstOrFail();
        $comm2 = Commission::where('booking_id', $c2->id)->firstOrFail();

        $this->assertEqualsWithDelta(round($c1PriceFinal * 0.10, 2), (float) $comm1->platform_commission, 0.001);
        $this->assertEqualsWithDelta(round($c2PriceFinal * 0.10, 2), (float) $comm2->platform_commission, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $comm1->franchise_commission, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $comm2->franchise_commission, 0.001);

        $expectedProviderAEarnings = (float) $comm1->provider_commission + (float) $comm2->provider_commission;
        // 400 - 40 = 360 ; 600 - 60 = 540 ; total 900
        $this->assertEqualsWithDelta(900.0, $expectedProviderAEarnings, 0.001);

        // provider A's wallet holds exactly the two child payouts, nothing more.
        $this->assertEqualsWithDelta(
            $expectedProviderAEarnings,
            (float) $providerA->user->wallet->fresh()->balance,
            0.001,
        );
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$c1->id}:provider-earning")->count());
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$c2->id}:provider-earning")->count());

        // provider B settled nothing (its child was cancelled, never completed).
        $providerBWallet = Wallet::where('user_id', $providerB->user->id)->first();
        $this->assertTrue($providerBWallet === null || (float) $providerBWallet->balance === 0.0);
        $this->assertSame(0, Commission::whereIn('booking_id', [$c3->id])->count());

        // total commission recorded == total taken from the two completed children
        $totalCommission = (float) Commission::sum('provider_commission')
            + (float) Commission::sum('franchise_commission')
            + (float) Commission::sum('platform_commission');
        $this->assertEqualsWithDelta($c1PriceFinal + $c2PriceFinal, $totalCommission, 0.001);

        // customer ledger: one debit of the full bundle total, then one bundle
        // refund credit for child 3's un-delivered share. Net = paid for the
        // two children that were actually delivered.
        $this->assertEqualsWithDelta(
            $openingBalance - $bundleTotal + $expectedRefund,
            (float) $customer->wallet->fresh()->balance,
            0.001,
        );
        $this->assertEqualsWithDelta(
            $openingBalance - $c1PriceQuoted - $c2PriceQuoted,
            (float) $customer->wallet->fresh()->balance,
            0.001,
            'net customer spend == the two delivered children only',
        );
        // the only credit to the customer wallet is the single bundle refund
        $this->assertSame(0, WalletTransaction::where('ref', 'like', 'booking:%:wallet-refund')->count(), 'no per-child refund path was used');
        $customerCredits = WalletTransaction::where('is_credit', true)->where('wallet_id', $customer->wallet->id)->get();
        $this->assertCount(1, $customerCredits);
        $this->assertSame("booking_bundle:{$bundle->id}:wallet-refund", $customerCredits->first()->ref);
    }
}
