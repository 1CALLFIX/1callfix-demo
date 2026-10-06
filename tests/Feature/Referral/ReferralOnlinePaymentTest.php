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

    private function laterBooking(array $s, string $method, string $paymentStatus): Booking
    {
        return Booking::create([
            'code' => 'TSTL-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $s['franchise']->id, 'zone_id' => $s['zone']->id,
            'customer_id' => $s['customer']->id, 'service_id' => $s['booking']->service_id, 'address_id' => $s['address']->id,
            'status' => 'completed', 'price_quoted' => 500, 'payment_status' => $paymentStatus, 'payment_method' => $method,
        ]);
    }

    public function test_a_cash_first_booking_then_an_online_completed_booking_pays_the_reward_once(): void
    {
        [$referrer, $cash, $s] = $this->scenario('cash', 'paid');
        $svc = app(ReferralService::class);

        $this->assertNull($svc->qualifyFromCompletedBooking($cash));
        $online = $this->laterBooking($s, 'online', 'paid');

        $referral = $svc->qualifyFromCompletedBooking($online);

        $this->assertSame('rewarded', $referral->status);
        $this->assertSame($online->id, $referral->qualifying_booking_id);
        $this->assertEquals(50.0, app(WalletService::class)->balance($referrer));
    }

    public function test_several_qualifying_online_bookings_pay_the_reward_only_once(): void
    {
        [$referrer, $first, $s] = $this->scenario('online', 'paid');
        $svc = app(ReferralService::class);

        $svc->qualifyFromCompletedBooking($first);
        $this->assertNull($svc->qualifyFromCompletedBooking($this->laterBooking($s, 'online', 'paid')));
        $this->assertNull($svc->qualifyFromCompletedBooking($this->laterBooking($s, 'wallet', 'paid')));

        $this->assertEquals(50.0, app(WalletService::class)->balance($referrer));
    }

    public function test_later_cash_or_cash_plus_online_bookings_do_not_qualify(): void
    {
        [$referrer, $first, $s] = $this->scenario('cash', 'paid');
        $svc = app(ReferralService::class);

        $svc->qualifyFromCompletedBooking($first);
        $this->assertNull($svc->qualifyFromCompletedBooking($this->laterBooking($s, 'cash', 'paid')));
        $this->assertNull($svc->qualifyFromCompletedBooking($this->laterBooking($s, 'cash+online', 'paid')));

        $this->assertSame('pending', Referral::where('referrer_id', $referrer->id)->firstOrFail()->status);
        $this->assertEquals(0.0, app(WalletService::class)->balance($referrer));
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
