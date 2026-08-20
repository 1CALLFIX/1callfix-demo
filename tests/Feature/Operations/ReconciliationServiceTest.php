<?php

namespace Tests\Feature\Operations;

use App\Models\Commission;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Operations\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
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
    use ParcelOrderFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    public function test_paid_booking_without_captured_payment_is_flagged(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->update(['payment_status' => 'paid']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertTrue($result['paid_bookings_without_captured_payment']->contains('id', $scenario['booking']->id));
    }

    public function test_completed_booking_without_commission_is_flagged(): void
    {
        $scenario = $this->makeBookingScenario('completed');

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertTrue($result['completed_bookings_without_commission']->contains('id', $scenario['booking']->id));
    }

    public function test_completed_booking_with_commission_is_not_flagged(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertFalse($result['completed_bookings_without_commission']->contains('id', $scenario['booking']->id));
    }

    public function test_wallet_balance_mismatch_is_flagged(): void
    {
        $customer = $this->makeCustomer();
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 500]);
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test-mismatch', 'status' => 'successful']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertTrue($result['wallet_balance_mismatches']->contains(fn ($row) => $row['wallet']->id === $wallet->id));
    }

    public function test_matching_wallet_balance_is_not_flagged(): void
    {
        $customer = $this->makeCustomer();
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 100]);
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test-match', 'status' => 'successful']);
        // A failed transaction on the same wallet must NOT count toward the
        // ledger sum -- proves the rewritten grouped-SQL version still
        // filters on status='successful' the same way the old per-wallet
        // loop did, not just that it returns the right total by accident.
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 500, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test-failed', 'status' => 'failed']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertFalse($result['wallet_balance_mismatches']->contains(fn ($row) => $row['wallet']->id === $wallet->id));
    }

    /**
     * Mission Phase 18 (performance/scale audit) regression test:
     * walletBalanceMismatches() used to run one extra query PER wallet
     * (a real, unbounded N+1 -- 10,001 queries for 10,000 wallets). Proves
     * the rewrite's query count no longer scales with wallet count: three
     * wallets costs the same query budget as one would.
     */
    public function test_wallet_balance_mismatch_check_does_not_n_plus_one_per_wallet(): void
    {
        foreach (range(1, 3) as $i) {
            $customer = $this->makeCustomer();
            $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 100 * $i]);
            WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100 * $i, 'is_credit' => true, 'reason' => 'test', 'ref' => "n1-{$i}", 'status' => 'successful']);
        }

        $admin = $this->makeSuperAdmin();

        DB::enableQueryLog();
        (new ReconciliationService)->detect($admin);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Loose upper bound (this test doesn't care about the OTHER four
        // checks' own query counts, only that this one check's cost is flat
        // per-wallet, not linear) -- well under what 3 wallets would cost
        // at even 2 queries/wallet under the old N+1 shape, let alone the
        // real "load every wallet then one more query per wallet" pattern.
        $this->assertLessThan(20, $queryCount);
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

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

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

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertFalse($result['wallet_topups_captured_without_credit']->contains('id', $payment->id));
    }

    public function test_pending_wallet_topup_is_not_flagged(): void
    {
        $customer = $this->makeCustomer();
        Payment::create([
            'purpose' => 'wallet_topup', 'user_id' => $customer->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_pending', 'status' => 'pending',
        ]);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

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

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $flagged = $result['negative_loyalty_balances']->firstWhere('user_id', $customer->id);
        $this->assertNotNull($flagged);
        $this->assertSame(-100, (int) $flagged->balance);
    }

    public function test_positive_loyalty_balance_is_not_flagged(): void
    {
        $customer = $this->makeCustomer();
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => 100, 'reason' => 'earned']);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -40, 'reason' => 'redeemed']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertNull($result['negative_loyalty_balances']->firstWhere('user_id', $customer->id));
    }

    public function test_expired_earn_correctly_excluded_from_the_balance_check(): void
    {
        $customer = $this->makeCustomer();
        // An expired earn no longer counts -- redeeming against it (however
        // that happened) should show as negative, same as LoyaltyService::balance().
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => 100, 'reason' => 'earned', 'expires_at' => now()->subDay()]);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -50, 'reason' => 'redeemed']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $flagged = $result['negative_loyalty_balances']->firstWhere('user_id', $customer->id);
        $this->assertNotNull($flagged);
        $this->assertSame(-50, (int) $flagged->balance);
    }

    /**
     * Admin Command Center mission, Finance Command Center phase
     * (2026-08-20) — detect() took no viewer at all before this fix, so
     * every check ran completely unscoped. operations.view defaults to
     * super_admin-only, but its own seeding migration explicitly
     * anticipates a franchise-scoped grant existing one day ("assignable
     * to other roles... once a real business need shows up") — proves
     * that once one exists, a franchise-scoped viewer sees only their own
     * franchise's reconciliation warnings, not every franchise's.
     */
    public function test_reconciliation_checks_are_scoped_to_the_actors_own_franchise(): void
    {
        $mine = $this->makeBookingScenario('completed');
        $mine['booking']->update(['payment_status' => 'paid']);

        $other = $this->makeBookingScenario('completed');
        $other['booking']->update(['payment_status' => 'paid']);

        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $mine['franchise']->id);

        $result = (new ReconciliationService)->detect($actor);

        $this->assertTrue($result['paid_bookings_without_captured_payment']->contains('id', $mine['booking']->id));
        $this->assertFalse($result['paid_bookings_without_captured_payment']->contains('id', $other['booking']->id));
    }

    public function test_super_admin_sees_reconciliation_warnings_across_every_franchise(): void
    {
        $a = $this->makeBookingScenario('completed');
        $a['booking']->update(['payment_status' => 'paid']);
        $b = $this->makeBookingScenario('completed');
        $b['booking']->update(['payment_status' => 'paid']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertTrue($result['paid_bookings_without_captured_payment']->contains('id', $a['booking']->id));
        $this->assertTrue($result['paid_bookings_without_captured_payment']->contains('id', $b['booking']->id));
    }

    /**
     * Finding (2) in ReconciliationService's own docblock: the original
     * two checks only ever covered Booking. Parcel/Taxi/Property/
     * Marketplace/Rental/Hotel all take real payments and pay real
     * commission through the identical Payment/Commission tables — proven
     * here for one dispatch-shaped vertical (Parcel) and one Reservation-
     * family vertical (Property), not all six, since all six share the
     * exact same loop body in orderPaidWithoutCapturedPayment()/
     * orderCompletedWithoutCommission().
     */
    public function test_parcel_order_paid_without_captured_payment_is_flagged(): void
    {
        $scenario = $this->makeParcelOrderScenario('delivered');
        $scenario['order']->update(['payment_status' => 'paid']);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertTrue($result['order_paid_without_captured_payment']->contains('id', $scenario['order']->id));
    }

    public function test_parcel_order_paid_with_captured_payment_is_not_flagged(): void
    {
        $scenario = $this->makeParcelOrderScenario('delivered');
        $scenario['order']->update(['payment_status' => 'paid']);
        Payment::create([
            'purpose' => 'parcel_order', 'parcel_order_id' => $scenario['order']->id, 'user_id' => $scenario['customer']->id,
            'amount' => 100, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_parcel_ok', 'status' => 'captured', 'captured_at' => now(),
        ]);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertFalse($result['order_paid_without_captured_payment']->contains('id', $scenario['order']->id));
    }

    public function test_property_reservation_completed_without_commission_is_flagged(): void
    {
        $scenario = $this->makePropertyReservationScenario('completed');

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertTrue($result['order_completed_without_commission']->contains('id', $scenario['reservation']->id));
    }

    public function test_property_reservation_completed_with_commission_is_not_flagged(): void
    {
        $scenario = $this->makePropertyReservationScenario('completed');
        Commission::create(['property_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);

        $result = (new ReconciliationService)->detect($this->makeSuperAdmin());

        $this->assertFalse($result['order_completed_without_commission']->contains('id', $scenario['reservation']->id));
    }

    public function test_order_level_reconciliation_checks_are_scoped_to_the_actors_own_franchise(): void
    {
        $mine = $this->makeParcelOrderScenario('delivered');
        $mine['order']->update(['payment_status' => 'paid']);

        $other = $this->makeParcelOrderScenario('delivered');
        $other['order']->update(['payment_status' => 'paid']);

        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $mine['franchise']->id);

        $result = (new ReconciliationService)->detect($actor);

        $this->assertTrue($result['order_paid_without_captured_payment']->contains('id', $mine['order']->id));
        $this->assertFalse($result['order_paid_without_captured_payment']->contains('id', $other['order']->id));
    }
}
