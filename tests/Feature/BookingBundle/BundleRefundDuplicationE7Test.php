<?php

namespace Tests\Feature\BookingBundle;

use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E7 — risk-table item 7 ("partial-refund duplication"), pinned at the
 * refund engine itself rather than only through the cancel endpoint's FSM
 * guard.
 *
 *   1. CancellationService::refundIfPaid() is idempotent: the captured
 *      Payment flips to `refunded` on the first call, so a second call finds
 *      no captured Payment and moves no money — a doubled refund is
 *      impossible even if the caller's own "already cancelled" guard were
 *      bypassed.
 *
 *   2. A bundle child has no Payment of its own (E3 keeps ONE Payment per
 *      bundle, keyed booking_bundle_id), so refundIfPaid() on a bundle child
 *      is a silent no-op — there is currently NO bundle-level partial-refund
 *      path to duplicate. This is the known E5-deferred gap; the test pins
 *      today's behaviour so it can't regress silently.
 */
class BundleRefundDuplicationE7Test extends TestCase
{
    use BundleConsolidationHelpers;
    use RefreshDatabase;

    public function test_refund_if_paid_is_idempotent_for_a_single_booking(): void
    {
        $ctx = $this->makeWorld();
        $customer = $ctx['customer'] = $this->makeCustomer();
        $ctx['address'] = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        Wallet::create(['user_id' => $customer->id, 'balance' => 0]);

        $service = $this->makeService($ctx['category'], 60, ['base_price' => 500]);
        $booking = $this->makeScheduledBooking($ctx, $service, null, 'cancelled', [
            'price_quoted' => 500, 'payment_method' => 'wallet', 'payment_status' => 'paid',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
        ]);

        $cancellation = app(CancellationService::class);

        // fee 50 -> refund 450, credited once
        $cancellation->refundIfPaid($booking->fresh(), 50.0);
        $cancellation->refundIfPaid($booking->fresh(), 50.0); // replay

        $this->assertSame(
            1,
            WalletTransaction::where('ref', "booking:{$booking->id}:wallet-refund")->count(),
            'the wallet is credited exactly once, no matter how many times refundIfPaid runs',
        );
        $this->assertEqualsWithDelta(450.0, (float) $customer->wallet->fresh()->balance, 0.001);

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertEqualsWithDelta(450.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('partially_refunded', $booking->fresh()->payment_status);
    }

    public function test_refund_if_paid_on_a_bundle_child_moves_no_money_and_leaves_the_bundle_payment_intact(): void
    {
        $ctx = $this->makeWorld();
        $customer = $ctx['customer'] = $this->makeCustomer();
        Wallet::create(['user_id' => $customer->id, 'balance' => 0]);
        $ctx['address'] = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        $service = $this->makeService($ctx['category'], 60, ['base_price' => 500]);

        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, [
            ['service' => $service, 'scheduled_at' => '2030-06-01 09:00:00'],
            ['service' => $service, 'scheduled_at' => '2030-06-02 09:00:00'],
        ]);
        [$child] = $rows;

        // the ONE bundle-level captured Payment (E3 shape) — no per-child row
        $bundlePayment = Payment::create([
            'booking_bundle_id' => $bundle->id, 'purpose' => 'booking_bundle', 'amount' => 1000,
            'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
        ]);

        app(CancellationService::class)->refundIfPaid($child->fresh(), 0.0);

        $this->assertEqualsWithDelta(0.0, (float) $customer->wallet->fresh()->balance, 0.001, 'no refund was issued for the bundle child');
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame('captured', $bundlePayment->fresh()->status, 'the bundle Payment is untouched');
        $this->assertNull($bundlePayment->fresh()->refunded_amount);
    }
}
