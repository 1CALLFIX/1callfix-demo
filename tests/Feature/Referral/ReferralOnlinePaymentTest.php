<?php

namespace Tests\Feature\Referral;

use App\Actions\CreateBookingAction;
use App\Models\Booking;
use App\Models\Referral;
use App\Services\ReferralService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * D8 (docs/COUPON_HARDENING_FINAL.md) — the referrer reward is paid only after the referred customer's
 * qualifying booking is COMPLETED and was paid ONLINE (Razorpay, wallet, or both). No reward for code entry,
 * registration, booking created/assigned, payment initiated, or a cash / cash+online completion.
 *
 * The referee discount does not exist in this codebase (no code path gives a referred customer a discount),
 * so there is nothing to gate there — reported as NOT IMPLEMENTED, not invented.
 */
class ReferralOnlinePaymentTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;
    use \Tests\Feature\Support\WithLegacyReferral;

    private function scenario(string $method, string $paymentStatus): array
    {
        $referrer = $this->makeCustomer();
        $s = $this->makeBookingScenario('completed');
        $s['booking']->update(['payment_method' => $method, 'payment_status' => $paymentStatus]);
        $s['customer']->update(['referred_by' => $referrer->id]);
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $s['customer']->id, 'status' => 'pending']);

        return [$referrer, $s['booking']->fresh(), $s];
    }

    private function assertNoReward(string $method, string $paymentStatus): void
    {
        [$referrer, $booking] = $this->scenario($method, $paymentStatus);

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('pending', Referral::where('referrer_id', $referrer->id)->firstOrFail()->status);
        $this->assertEquals(0.0, app(WalletService::class)->balance($referrer));
    }

    public function test_a_cash_completion_pays_no_reward(): void
    {
        $this->assertNoReward('cash', 'pending');
        $this->assertNoReward('cash', 'paid');
    }

    public function test_a_cash_plus_online_completion_pays_no_reward(): void
    {
        foreach (['split', 'cash+online', 'cash_online'] as $method) {
            $this->assertNoReward($method, 'paid');
        }
    }

    public function test_an_online_booking_whose_payment_is_only_initiated_pays_no_reward(): void
    {
        $this->assertNoReward('online', 'pending');
    }

    public function test_an_online_paid_completion_rewards_the_referrer_once(): void
    {
        [$referrer, $booking] = $this->scenario('online', 'paid');

        $referral = app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertSame('rewarded', $referral->status);
        $this->assertEquals(50.0, app(WalletService::class)->balance($referrer));
        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking), 'Idempotent.');
    }

    public function test_a_wallet_paid_completion_rewards_the_referrer(): void
    {
        [$referrer, $booking] = $this->scenario('wallet', 'paid');

        $this->assertSame('rewarded', app(ReferralService::class)->qualifyFromCompletedBooking($booking)->status);
    }

    public function test_code_entry_registration_and_booking_creation_pay_no_reward(): void
    {
        $referrer = $this->makeCustomer();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $newUser = $this->makeCustomer();
        $newUser->update(['referred_by' => $referrer->id]);
        $referral = app(ReferralService::class)->createFromSignup($newUser->fresh());
        $address = $this->makeAddress($newUser, $franchise, $zone);

        Queue::fake();
        app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $newUser->id,
            'service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'online',
        ]);

        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertEquals(0.0, app(WalletService::class)->balance($referrer));
    }
}
