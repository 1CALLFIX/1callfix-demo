<?php

namespace Tests\Feature\Earnings;

use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BundleSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D3. Every wallet refund of one bundle used
 * the SAME ref `booking_bundle:{id}:wallet-refund` against the unique
 * `wallet_transactions.ref` index, so the FIRST child cancel refunded and the
 * SECOND blew up on the unique constraint AFTER its cancellation had already
 * committed — the child ended cancelled with its money never returned.
 */
class BundleSequentialWalletRefundTest extends TestCase
{
    use BundleConsolidationHelpers;
    use RefreshDatabase;

    /** @return array{bundle: BookingBundle, children: \Illuminate\Support\Collection<int, Booking>, customer: \App\Models\User, opening: float} */
    private function makeWalletBundle(array $prices, float $opening = 100000.0): array
    {
        Queue::fake();
        Setting::set('payment.wallet_enabled', '1');

        $ctx = $this->makeWorld();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        Wallet::create(['user_id' => $customer->id, 'balance' => $opening]);

        $services = array_map(fn ($p) => $this->makeService($ctx['category'], 60, ['base_price' => $p]), $prices);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/booking-bundles', [
                'payment_method' => 'wallet',
                'services' => array_map(fn ($s) => ['service_id' => $s->id, 'address_id' => $address->id], $services),
            ])
            ->assertStatus(201);

        $bundle = BookingBundle::latest('id')->firstOrFail()->load('children');

        return ['bundle' => $bundle, 'children' => $bundle->children->values(), 'customer' => $customer, 'opening' => $opening];
    }

    private function refundRows(BookingBundle $bundle)
    {
        return WalletTransaction::where('ref', 'like', "booking_bundle:{$bundle->id}:wallet-refund%")->orderBy('id')->get();
    }

    public function test_two_separate_child_cancels_on_one_wallet_paid_bundle_each_refund_their_own_delta(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer, 'opening' => $opening]
            = $this->makeWalletBundle([400, 600, 500]); // total 1500, free window → fee 0

        $afterPay = (float) Wallet::where('user_id', $customer->id)->value('balance');
        $this->assertEqualsWithDelta($opening - 1500, $afterPay, 0.001);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$children[0]->id}/cancel", ['reason' => 'first'])
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$children[1]->id}/cancel", ['reason' => 'second'])
            ->assertOk();

        $rows = $this->refundRows($bundle);
        $this->assertCount(2, $rows, 'each child cancel must write its own refund row');
        $this->assertEqualsWithDelta(400.0, (float) $rows[0]->amount, 0.001, 'first refund = child 1 share');
        $this->assertEqualsWithDelta(600.0, (float) $rows[1]->amount, 0.001, 'second refund = delta only, never the full amount again');
        $this->assertNotSame($rows[0]->ref, $rows[1]->ref);

        $payment = Payment::where('booking_bundle_id', $bundle->id)->where('purpose', 'booking_bundle')->firstOrFail();
        $this->assertEqualsWithDelta(1000.0, (float) $payment->refunded_amount, 0.001);
        $this->assertSame('partially_refunded', $payment->status);

        $this->assertEqualsWithDelta($afterPay + 1000, (float) Wallet::where('user_id', $customer->id)->value('balance'), 0.001);
    }

    public function test_retrying_the_same_child_settlement_never_double_refunds(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer]
            = $this->makeWalletBundle([400, 600]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$children[0]->id}/cancel", ['reason' => 'first'])
            ->assertOk();

        $balance = (float) Wallet::where('user_id', $customer->id)->value('balance');

        // A retry of the same child's cancel is refused by the FSM (409) …
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$children[0]->id}/cancel", ['reason' => 'again'])
            ->assertStatus(409);

        // … and re-running the settlement itself (the safe-retry path) is a no-op.
        $this->assertNull(app(BundleSettlementService::class)->settleFromChildren($bundle->id, $children[0]->id));
        $this->assertNull(app(BundleSettlementService::class)->settleFromChildren($bundle->id));

        $this->assertCount(1, $this->refundRows($bundle));
        $this->assertEqualsWithDelta($balance, (float) Wallet::where('user_id', $customer->id)->value('balance'), 0.001);
    }

    public function test_a_refund_lost_to_a_failed_settlement_is_recovered_by_rerunning_settlement(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer]
            = $this->makeWalletBundle([400, 600]);

        // Cancel the child WITHOUT reconciling — exactly the committed state a
        // settlement failure after the cancel transaction leaves behind.
        app(\App\Actions\AdminCancelBookingAction::class)->execute($children[0]->id, 'lost', reconcileBundle: false);
        $this->assertCount(0, $this->refundRows($bundle));

        $this->artisan('bundles:refund-audit')->expectsOutputToContain("bundle #{$bundle->id}")->assertExitCode(1);

        $refunded = app(BundleSettlementService::class)->settleFromChildren($bundle->id);
        $this->assertEqualsWithDelta(400.0, (float) $refunded, 0.001);

        $this->artisan('bundles:refund-audit')->expectsOutputToContain('No cancelled bundle child is missing a refund')->assertExitCode(0);
    }

    public function test_whole_bundle_cancel_after_a_single_child_cancel_refunds_only_the_remainder(): void
    {
        ['bundle' => $bundle, 'children' => $children, 'customer' => $customer]
            = $this->makeWalletBundle([400, 600, 500]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$children[0]->id}/cancel", ['reason' => 'one'])
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/booking-bundles/{$bundle->id}/cancel", ['reason' => 'rest'])
            ->assertOk();

        $rows = $this->refundRows($bundle);
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1500.0, $rows->sum(fn ($r) => (float) $r->amount), 0.001);
        $this->assertSame('refunded', Payment::where('booking_bundle_id', $bundle->id)->value('status'));
    }
}
