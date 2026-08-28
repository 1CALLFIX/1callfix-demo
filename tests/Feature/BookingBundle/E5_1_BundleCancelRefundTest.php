<?php

namespace Tests\Feature\BookingBundle;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\StartBookingAction;
use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BundleSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E5.1 — closes the two gaps Phase E7 QA found and pinned:
 *
 *   Gap 1 — a paid bundle child could only be cancelled through the
 *   single-booking endpoint, whose refund path (`refundIfPaid`, keyed to
 *   `Payment.booking_id`) finds nothing for a bundle child (E3 keeps ONE
 *   Payment per bundle). Cancelling a paid bundle child refunded nothing.
 *
 *   Gap 2 — `BookingBundle.status` (the stored enum) was never advanced from
 *   'active'; only the computed `derivedStatus()` reflected reality.
 *
 * Every financial assertion here re-fetches the persisted row (Payment,
 * Wallet, WalletTransaction) — a "mock was called" / "HTTP 200" is never
 * treated as proof.
 */
class E5_1_BundleCancelRefundTest extends TestCase
{
    use BundleConsolidationHelpers;
    use RefreshDatabase;

    /**
     * Create a wallet-paid bundle through the real HTTP endpoint.
     *
     * @param  array<int, float>  $prices  one base price per child service
     * @return array{bundle: BookingBundle, children: \Illuminate\Support\Collection<int, Booking>, customer: \App\Models\User, ctx: array, opening: float}
     */
    private function makeWalletBundle(array $prices, float $opening = 100000.0): array
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        Wallet::create(['user_id' => $customer->id, 'balance' => $opening]);

        $services = array_map(fn ($p) => $this->makeService($ctx['category'], 60, ['base_price' => $p]), $prices);

        $payload = [
            'payment_method' => 'wallet',
            'services' => array_map(fn ($s) => ['service_id' => $s->id, 'address_id' => $address->id], $services),
        ];

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/booking-bundles', $payload)
            ->assertStatus(201);

        $bundle = BookingBundle::latest('id')->firstOrFail();
        $bundle->load('children');

        return [
            'bundle' => $bundle,
            'children' => $bundle->children->values(),
            'customer' => $customer,
            'ctx' => $ctx + ['address' => $address],
            'opening' => $opening,
        ];
    }

    /** Take one bundle child all the way to completed through the real Actions. */
    private function completeChild(Booking $child, array $ctx): Provider
    {
        $provider = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);
        Booking::whereKey($child->id)->update(['status' => 'searching_provider']);
        $this->offer($child->fresh(), $provider);

        $accepted = app(AcceptBookingAction::class)->execute($child->id, $provider);
        app(StartBookingAction::class)->execute($child->id, $accepted->start_otp);
        app(CompleteBookingAction::class)->execute($child->id, $provider, $accepted->fresh()->completion_otp);

        $this->assertSame('completed', $child->fresh()->status);

        return $provider;
    }

    private function bundlePayment(BookingBundle $bundle): Payment
    {
        return Payment::where('booking_bundle_id', $bundle->id)->where('purpose', 'booking_bundle')->firstOrFail();
    }

    private function walletBalance(int $userId): float
    {
        return (float) Wallet::where('user_id', $userId)->firstOrFail()->balance;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gap 1 — bundle refund
    // ─────────────────────────────────────────────────────────────────────

    public function test_cancelling_a_fully_pending_wallet_bundle_refunds_the_full_total(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer, 'opening' => $opening]
            = $this->makeWalletBundle([400, 600, 500]); // total 1500, free-window fee 0

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // every child cancelled, none refunded independently
        foreach ($children as $child) {
            $this->assertSame('cancelled', $child->fresh()->status);
            $this->assertSame(0, Payment::where('booking_id', $child->id)->count(), 'a bundle child must never get its own Payment row');
        }

        // the ONE shared Payment took the whole refund
        $payment = $this->bundlePayment($bundle);
        $this->assertEqualsWithDelta(1500.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('refunded', $payment->status);
        $this->assertSame(1, Payment::where('booking_bundle_id', $bundle->id)->count());

        // wallet reconciles exactly: one credit, balance restored
        $refunds = WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->get();
        $this->assertCount(1, $refunds);
        $this->assertEqualsWithDelta(1500.0, (float) $refunds->first()->amount, 0.001);
        $this->assertTrue((bool) $refunds->first()->is_credit);
        $this->assertEqualsWithDelta($opening, $this->walletBalance($customer->id), 0.001);

        // bundle rows
        $fresh = BookingBundle::findOrFail($bundle->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->cancellation_fee, 0.001);
    }

    public function test_cancellation_fees_are_summed_and_retained_across_children(): void
    {
        Setting::set('cancellation.free_minutes', '0');
        Setting::set('cancellation.fee_type', 'flat');
        Setting::set('cancellation.fee_value', '50');

        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer, 'opening' => $opening]
            = $this->makeWalletBundle([400, 600, 500]); // total 1500

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'fee test'])
            ->assertOk();

        foreach ($children as $child) {
            $this->assertEqualsWithDelta(50.0, (float) $child->fresh()->cancellation_fee, 0.001);
        }

        // retained = Σ fees = 150 ; refund = 1500 − 150 = 1350
        $payment = $this->bundlePayment($bundle);
        $this->assertEqualsWithDelta(1350.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('partially_refunded', $payment->status);

        $this->assertEqualsWithDelta(1350.0, (float) WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->sum('amount'), 0.001);
        $this->assertEqualsWithDelta($opening - 150.0, $this->walletBalance($customer->id), 0.001);

        $fresh = BookingBundle::findOrFail($bundle->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('partially_refunded', $fresh->payment_status);
        $this->assertEqualsWithDelta(150.0, (float) $fresh->cancellation_fee, 0.001);
    }

    public function test_online_paid_bundle_cancellation_issues_one_gateway_refund_for_the_remainder(): void
    {
        Setting::set('cancellation.free_minutes', '0');
        Setting::set('cancellation.fee_type', 'flat');
        Setting::set('cancellation.fee_value', '25');

        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer]
            = $this->makeWalletBundle([400, 600]); // built as wallet, then re-cast as an online capture below

        // Re-cast the captured Payment as a gateway (razorpay) capture — the
        // state RazorpayWebhookHandler::handleCaptured leaves for an online bundle.
        $payment = $this->bundlePayment($bundle);
        $payment->update(['gateway' => 'razorpay', 'gateway_payment_id' => 'pay_bundle_online', 'gateway_order_id' => 'order_bundle_online']);

        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('identifier')->andReturn('razorpay')->byDefault();
        $mock->shouldReceive('refund')->once()
            ->with('pay_bundle_online', 950.0, \Mockery::type('string')) // 1000 − (25 + 25) fees
            ->andReturn(['id' => 'rfnd_1', 'amount' => 95000, 'status' => 'processed']);
        $this->app->instance(PaymentGateway::class, $mock);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'online refund'])
            ->assertOk();

        // persisted state, not just "mock was called"
        $payment->refresh();
        $this->assertEqualsWithDelta(950.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('partially_refunded', $payment->status);

        // no wallet credit for a gateway refund
        $this->assertSame(0, WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->count());

        foreach ($children as $child) {
            $this->assertSame('cancelled', $child->fresh()->status);
        }
        $this->assertSame('cancelled', BookingBundle::findOrFail($bundle->id)->status);
    }

    public function test_a_completed_child_is_untouched_and_its_price_is_retained_from_the_refund(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer, 'ctx' => $ctx, 'opening' => $opening]
            = $this->makeWalletBundle([400, 600, 500]); // total 1500, free-window fee 0

        [$c1, $c2, $c3] = [$children[0], $children[1], $children[2]];
        $providerForC1 = $this->completeChild($c1, $ctx);
        $providerEarnings = $this->walletBalance($providerForC1->user->id);
        $this->assertGreaterThan(0.0, $providerEarnings, 'the completed child settled its provider');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'cancel the rest'])
            ->assertOk();

        // completed child fully untouched
        $c1->refresh();
        $this->assertSame('completed', $c1->status);
        $this->assertNull($c1->cancellation_fee);
        $this->assertSame(1, Commission::where('booking_id', $c1->id)->count());
        $this->assertEqualsWithDelta($providerEarnings, $this->walletBalance($providerForC1->user->id), 0.001, 'no clawback of a delivered service');

        // the other two cancelled
        $this->assertSame('cancelled', $c2->fresh()->status);
        $this->assertSame('cancelled', $c3->fresh()->status);

        // retained = 400 (delivered c1) + 0 + 0 fees ; refund = 1500 − 400 = 1100
        $payment = $this->bundlePayment($bundle);
        $this->assertEqualsWithDelta(1100.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('partially_refunded', $payment->status);
        $this->assertEqualsWithDelta(1100.0, (float) WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->sum('amount'), 0.001);
        $this->assertEqualsWithDelta($opening - 1500.0 + 1100.0, $this->walletBalance($customer->id), 0.001);

        // ≥1 completed + rest terminal -> latch 'completed'
        $this->assertSame('completed', BookingBundle::findOrFail($bundle->id)->status);
    }

    public function test_cancelling_one_child_via_the_single_booking_endpoint_reconciles_the_shared_payment_without_latching(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer, 'ctx' => $ctx, 'opening' => $opening]
            = $this->makeWalletBundle([400, 600, 500]); // free-window fee 0

        [$c1, $c2, $c3] = [$children[0], $children[1], $children[2]];
        $this->completeChild($c1, $ctx);

        // cancel ONLY child 2, through the pre-existing single-booking endpoint
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$c2->id}/cancel", ['reason' => 'drop this one'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('completed', $c1->fresh()->status);
        $this->assertSame('cancelled', $c2->fresh()->status);
        $this->assertContains($c3->fresh()->status, ['pending', 'searching_provider'], 'child 3 untouched, still active');

        // the shared Payment was reconciled for child 2 only: refund = 600 − 0
        $payment = $this->bundlePayment($bundle);
        $this->assertEqualsWithDelta(600.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('partially_refunded', $payment->status);
        $this->assertSame(0, Payment::where('booking_id', $c2->id)->count(), 'still no per-child Payment row');

        $this->assertEqualsWithDelta(600.0, (float) WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->sum('amount'), 0.001);
        $this->assertEqualsWithDelta($opening - 1500.0 + 600.0, $this->walletBalance($customer->id), 0.001);

        // NOT all children terminal -> stored latch stays 'active'
        $fresh = BookingBundle::findOrFail($bundle->id);
        $this->assertSame('active', $fresh->status);
        $this->assertSame('partially_completed', $fresh->derivedStatus());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Idempotency / no double refund
    // ─────────────────────────────────────────────────────────────────────

    public function test_cancelling_an_already_cancelled_bundle_is_a_409_with_no_second_refund(): void
    {
        ['bundle' => $bundle, 'customer' => $customer, 'opening' => $opening] = $this->makeWalletBundle([400, 600]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'first'])
            ->assertOk();

        $balanceAfterFirst = $this->walletBalance($customer->id);
        $refundedAfterFirst = (float) $this->bundlePayment($bundle)->refunded_amount;
        $this->assertEqualsWithDelta($opening, $balanceAfterFirst, 0.001);
        $this->assertEqualsWithDelta(1000.0, $refundedAfterFirst, 0.001);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'second'])
            ->assertStatus(409);

        $this->assertEqualsWithDelta($balanceAfterFirst, $this->walletBalance($customer->id), 0.001);
        $this->assertEqualsWithDelta($refundedAfterFirst, (float) $this->bundlePayment($bundle)->refunded_amount, 0.001);
        $this->assertSame(1, WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->count());
    }

    public function test_settle_from_children_is_idempotent_at_the_engine(): void
    {
        ['bundle' => $bundle, 'customer' => $customer, 'opening' => $opening] = $this->makeWalletBundle([400, 600]);

        // cancel both children WITHOUT reconciling (mimics CancelBookingBundleAction's loop)
        foreach ($bundle->children as $child) {
            app(\App\Actions\AdminCancelBookingAction::class)->execute($child->id, 'x', reconcileBundle: false);
        }

        $settlement = app(BundleSettlementService::class);
        $first = $settlement->settleFromChildren($bundle->id);
        $second = $settlement->settleFromChildren($bundle->id);
        $third = $settlement->settleFromChildren($bundle->id);

        $this->assertEqualsWithDelta(1000.0, (float) $first, 0.001);
        $this->assertNull($second, 'second settle refunds nothing');
        $this->assertNull($third);

        $this->assertSame(1, WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->count());
        $this->assertEqualsWithDelta($opening, $this->walletBalance($customer->id), 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->bundlePayment($bundle)->refunded_amount, 0.001);
        $this->assertSame('refunded', $this->bundlePayment($bundle)->status);
        $this->assertSame('cancelled', BookingBundle::findOrFail($bundle->id)->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gap 2 — persisted status latch
    // ─────────────────────────────────────────────────────────────────────

    public function test_last_child_completion_latches_the_bundle_to_completed_in_the_database(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'ctx' => $ctx] = $this->makeWalletBundle([400, 600]);

        $this->completeChild($children[0], $ctx);
        $this->assertSame('active', BookingBundle::findOrFail($bundle->id)->status, 'one of two done -> not yet latched');

        $this->completeChild($children[1], $ctx);

        // the STORED column, re-fetched fresh — not derivedStatus(), not the in-memory instance
        $this->assertSame('completed', BookingBundle::findOrFail($bundle->id)->status);
        $this->assertSame('completed', BookingBundle::findOrFail($bundle->id)->derivedStatus());

        // a completion never issues a refund
        $this->assertSame(0, WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->count());
        $this->assertSame('paid', BookingBundle::findOrFail($bundle->id)->payment_status);
    }

    public function test_all_children_cancelled_none_completed_latches_to_cancelled(): void
    {
        ['bundle' => $bundle, 'customer' => $customer] = $this->makeWalletBundle([400, 600]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'all off'])
            ->assertOk();

        $this->assertSame('cancelled', BookingBundle::findOrFail($bundle->id)->status);
        $this->assertSame('cancelled', BookingBundle::findOrFail($bundle->id)->derivedStatus());
    }

    public function test_the_status_latch_is_idempotent_and_never_re_flips(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'ctx' => $ctx] = $this->makeWalletBundle([400, 600]);

        $this->completeChild($children[0], $ctx);
        $this->completeChild($children[1], $ctx);
        $this->assertSame('completed', BookingBundle::findOrFail($bundle->id)->status);

        // re-run the latch directly a few times — no throw, no change
        $settlement = app(BundleSettlementService::class);
        $settlement->settleFromChildren($bundle->id);
        $settlement->settleFromChildren($bundle->id);

        $this->assertSame('completed', BookingBundle::findOrFail($bundle->id)->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ownership / validation
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_customer_cannot_cancel_another_customers_bundle(): void
    {
        ['bundle' => $bundle] = $this->makeWalletBundle([400, 600]);
        $intruder = $this->makeCustomer();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'not mine'])
            ->assertStatus(404);

        $this->assertSame('active', BookingBundle::findOrFail($bundle->id)->status);
        $this->assertSame(0, WalletTransaction::where('ref', "booking_bundle:{$bundle->id}:wallet-refund")->count());
    }

    public function test_bundle_cancel_requires_a_reason(): void
    {
        ['bundle' => $bundle, 'customer' => $customer] = $this->makeWalletBundle([400, 600]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
