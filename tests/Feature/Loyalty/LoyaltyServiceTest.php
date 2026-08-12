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
}
