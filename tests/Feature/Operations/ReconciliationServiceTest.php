<?php

namespace Tests\Feature\Operations;

use App\Models\Commission;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Operations\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * ReconciliationService (mission Phase 10, extended Phase 15 — financial
 * reconciliation audit) had zero test coverage before this file, for all
 * five checks, not just the two Phase 15 added. Phase 15's own audit of
 * every money-moving path (campaigns, compensation, tips, referrals,
 * payouts) found those five reuse WalletService with real DB-level
 * idempotency guards and need no new check — the two gaps that DO exist
 * are covered here: wallet top-ups that Razorpay captured but never
 * credited (a non-atomic two-step commit in RazorpayWebhookHandler), and
 * a negative aggregate loyalty balance (only reachable via the race
 * LoyaltyService::redeem() had before this phase's locking fix).
 */
class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_paid_booking_without_captured_payment_is_flagged(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->update(['payment_status' => 'paid']);

        $result = (new ReconciliationService)->detect();

        $this->assertTrue($result['paid_bookings_without_captured_payment']->contains('id', $scenario['booking']->id));
    }

    public function test_completed_booking_without_commission_is_flagged(): void
    {
        $scenario = $this->makeBookingScenario('completed');

        $result = (new ReconciliationService)->detect();

        $this->assertTrue($result['completed_bookings_without_commission']->contains('id', $scenario['booking']->id));
    }

    public function test_completed_booking_with_commission_is_not_flagged(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);

        $result = (new ReconciliationService)->detect();

        $this->assertFalse($result['completed_bookings_without_commission']->contains('id', $scenario['booking']->id));
    }

    public function test_wallet_balance_mismatch_is_flagged(): void
    {
        $customer = $this->makeCustomer();
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 500]);
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test-mismatch', 'status' => 'successful']);

        $result = (new ReconciliationService)->detect();

        $this->assertTrue($result['wallet_balance_mismatches']->contains(fn ($row) => $row['wallet']->id === $wallet->id));
    }

    public function test_wallet_topup_captured_without_credit_is_flagged(): void
    {
        $customer = $this->makeCustomer();
        $payment = Payment::create([
            'purpose' => 'wallet_topup', 'user_id' => $customer->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_orphan', 'status' => 'captured', 'captured_at' => now(),
        ]);
        // Deliberately NO matching wallet_transactions row — simulates the
        // process dying between the two non-atomic commits.

        $result = (new ReconciliationService)->detect();

        $this->assertTrue($result['wallet_topups_captured_without_credit']->contains('id', $payment->id));
    }

    public function test_wallet_topup_captured_with_matching_credit_is_not_flagged(): void
    {
        $customer = $this->makeCustomer();
        $payment = Payment::create([
            'purpose' => 'wallet_topup', 'user_id' => $customer->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_ok', 'status' => 'captured', 'captured_at' => now(),
        ]);
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 500]);
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 500, 'is_credit' => true, 'reason' => 'Wallet top-up', 'ref' => "topup:{$payment->id}", 'status' => 'successful']);

        $result = (new ReconciliationService)->detect();

        $this->assertFalse($result['wallet_topups_captured_without_credit']->contains('id', $payment->id));
    }

    public function test_pending_wallet_topup_is_not_flagged(): void
    {
        $customer = $this->makeCustomer();
        Payment::create([
            'purpose' => 'wallet_topup', 'user_id' => $customer->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_pending', 'status' => 'pending',
        ]);

        $result = (new ReconciliationService)->detect();

        $this->assertCount(0, $result['wallet_topups_captured_without_credit']);
    }

    public function test_negative_loyalty_balance_is_flagged(): void
    {
        $customer = $this->makeCustomer();
        // Simulates the pre-fix race outcome directly: two redemptions
        // that together exceed what was ever earned.
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => 100, 'reason' => 'earned']);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -100, 'reason' => 'redeemed']);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -100, 'reason' => 'redeemed']);

        $result = (new ReconciliationService)->detect();

        $flagged = $result['negative_loyalty_balances']->firstWhere('user_id', $customer->id);
        $this->assertNotNull($flagged);
        $this->assertSame(-100, (int) $flagged->balance);
    }

    public function test_positive_loyalty_balance_is_not_flagged(): void
    {
        $customer = $this->makeCustomer();
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => 100, 'reason' => 'earned']);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -40, 'reason' => 'redeemed']);

        $result = (new ReconciliationService)->detect();

        $this->assertNull($result['negative_loyalty_balances']->firstWhere('user_id', $customer->id));
    }

    public function test_expired_earn_correctly_excluded_from_the_balance_check(): void
    {
        $customer = $this->makeCustomer();
        // An expired earn no longer counts -- redeeming against it (however
        // that happened) should show as negative, same as LoyaltyService::balance().
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => 100, 'reason' => 'earned', 'expires_at' => now()->subDay()]);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -50, 'reason' => 'redeemed']);

        $result = (new ReconciliationService)->detect();

        $flagged = $result['negative_loyalty_balances']->firstWhere('user_id', $customer->id);
        $this->assertNotNull($flagged);
        $this->assertSame(-50, (int) $flagged->balance);
    }
}
