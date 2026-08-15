<?php

namespace Tests\Feature\Loyalty;

use App\Models\LoyaltyPoint;
use App\Services\LoyaltyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Priority 2 required test area K (Loyalty). */
class LoyaltyServiceTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_earning_points_increases_balance(): void
    {
        $customer = $this->makeCustomer();
        $service = app(LoyaltyService::class);

        $service->earn($customer, 50, 'signup_bonus');

        $this->assertSame(50, $service->balance($customer));
    }

    public function test_earning_zero_or_negative_points_is_a_no_op(): void
    {
        $customer = $this->makeCustomer();
        $service = app(LoyaltyService::class);

        $this->assertNull($service->earn($customer, 0, 'noop'));
        $this->assertNull($service->earn($customer, -5, 'noop'));
        $this->assertSame(0, $service->balance($customer));
    }

    public function test_earning_twice_for_the_same_booking_and_reason_is_idempotent(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario();
        $service = app(LoyaltyService::class);

        $service->earn($customer, 30, 'booking_completed', $booking);
        $service->earn($customer, 30, 'booking_completed', $booking); // simulated retry

        $this->assertSame(30, $service->balance($customer));
        $this->assertSame(1, LoyaltyPoint::where('user_id', $customer->id)->where('booking_id', $booking->id)->count());
    }

    public function test_redeeming_points_credits_the_wallet_via_wallet_service(): void
    {
        $customer = $this->makeCustomer();
        $loyalty = app(LoyaltyService::class);
        $wallet = app(WalletService::class);
        $loyalty->earn($customer, 200, 'promo');

        $result = $loyalty->redeem($customer, 100); // default 10 points/rupee -> 10 rupees

        $this->assertSame(100, $result['points_redeemed']);
        $this->assertEquals(10.0, $result['rupees_credited']);
        $this->assertSame(100, $loyalty->balance($customer));
        $this->assertEquals(10.0, $wallet->balance($customer));
    }

    public function test_redeeming_more_than_the_balance_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $loyalty = app(LoyaltyService::class);
        $loyalty->earn($customer, 50, 'promo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient points balance');
        $loyalty->redeem($customer, 100);
    }

    public function test_redeeming_below_the_configured_minimum_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $loyalty = app(LoyaltyService::class);
        $loyalty->earn($customer, 1000, 'promo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Minimum redemption');
        $loyalty->redeem($customer, 10); // default minimum is 100
    }

    public function test_expired_points_no_longer_count_toward_balance(): void
    {
        $customer = $this->makeCustomer();
        LoyaltyPoint::create([
            'user_id' => $customer->id, 'points' => 40, 'reason' => 'old_promo',
            'expires_at' => now()->subDay(),
        ]);
        $service = app(LoyaltyService::class);

        $this->assertSame(0, $service->balance($customer));
    }

    /**
     * Phase 15 (financial reconciliation audit) finding: redeem()'s
     * balance check used to run BEFORE its transaction opened, against an
     * unlocked SUM() — two concurrent redemptions for the same user could
     * both read a sufficient balance and both succeed, driving the
     * aggregate ledger negative. Fixed by moving the check inside the
     * transaction, behind a lockForUpdate() on the user's own ledger rows
     * (same guarantee WalletService::applyTransaction() already gives
     * wallets.balance). PHPUnit is single-threaded, so this can't fire two
     * real concurrent redeem() calls — same honest limitation
     * ServiceMatchingJobRaceTest's own docblock already states for its
     * race — what these tests CAN and do verify is that the fix didn't
     * change redeem()'s observable behavior for the normal (non-racing)
     * case, including the exact boundary the balance check enforces.
     */
    public function test_redeeming_exactly_the_full_balance_succeeds(): void
    {
        $customer = $this->makeCustomer();
        $loyalty = app(LoyaltyService::class);
        $loyalty->earn($customer, 100, 'promo');

        $result = $loyalty->redeem($customer, 100);

        $this->assertSame(0, $result['new_balance']);
        $this->assertSame(0, $loyalty->balance($customer));
    }

    public function test_redeem_never_leaves_a_negative_balance_on_the_reconciliation_check(): void
    {
        $customer = $this->makeCustomer();
        $loyalty = app(LoyaltyService::class);
        $loyalty->earn($customer, 500, 'promo');

        $loyalty->redeem($customer, 200);
        $loyalty->redeem($customer, 100);

        $flagged = (new \App\Services\Operations\ReconciliationService)->detect()['negative_loyalty_balances']
            ->firstWhere('user_id', $customer->id);
        $this->assertNull($flagged, 'Two sequential, correctly-guarded redemptions must never show up as a reconciliation drift.');
        $this->assertSame(200, $loyalty->balance($customer));
    }
}
