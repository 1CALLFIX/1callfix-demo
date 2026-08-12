<?php

namespace Tests\Feature\Referral;

use App\Models\Referral;
use App\Services\ReferralService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Priority 2 required test area L (Referral). */
class ReferralServiceTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_signup_with_a_referrer_creates_a_pending_referral(): void
    {
        $referrer = $this->makeCustomer();
        $newUser = $this->makeCustomer();
        $newUser->update(['referred_by' => $referrer->id]);

        $referral = app(ReferralService::class)->createFromSignup($newUser->fresh());

        $this->assertNotNull($referral);
        $this->assertSame('pending', $referral->status);
        $this->assertSame($referrer->id, $referral->referrer_id);
    }

    public function test_signup_without_a_referrer_creates_nothing(): void
    {
        $newUser = $this->makeCustomer();

        $referral = app(ReferralService::class)->createFromSignup($newUser);

        $this->assertNull($referral);
        $this->assertSame(0, Referral::count());
    }

    public function test_self_referral_is_rejected(): void
    {
        $user = $this->makeCustomer();
        $user->update(['referred_by' => $user->id]);

        $referral = app(ReferralService::class)->createFromSignup($user->fresh());

        $this->assertNull($referral);
    }

    public function test_a_user_cannot_be_referred_twice(): void
    {
        $referrerA = $this->makeCustomer();
        $referrerB = $this->makeCustomer();
        $newUser = $this->makeCustomer();
        $newUser->update(['referred_by' => $referrerA->id]);
        app(ReferralService::class)->createFromSignup($newUser->fresh());

        $newUser->update(['referred_by' => $referrerB->id]); // simulated duplicate attempt
        $second = app(ReferralService::class)->createFromSignup($newUser->fresh());

        $this->assertNull($second);
        $this->assertSame(1, Referral::where('referred_id', $newUser->id)->count());
    }

    public function test_first_completed_booking_qualifies_and_rewards_the_referrer(): void
    {
        $referrer = $this->makeCustomer();
        ['booking' => $booking, 'customer' => $referred] = $this->makeBookingScenario('completed');
        $referred->update(['referred_by' => $referrer->id]);
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'status' => 'pending']);

        $referral = app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertNotNull($referral);
        $this->assertSame('rewarded', $referral->status);
        $this->assertSame($booking->id, $referral->qualifying_booking_id);
        $this->assertEquals(50.0, $referral->reward_amount); // default referral.reward_amount
        $this->assertEquals(50.0, app(WalletService::class)->balance($referrer));
    }

    public function test_second_completed_booking_does_not_qualify_or_double_reward(): void
    {
        $referrer = $this->makeCustomer();
        ['booking' => $firstBooking, 'customer' => $referred, 'franchise' => $franchise, 'zone' => $zone, 'category' => $category, 'address' => $address] = $this->makeBookingScenario('completed');
        $referred->update(['referred_by' => $referrer->id]);
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'status' => 'pending']);
        app(ReferralService::class)->qualifyFromCompletedBooking($firstBooking);

        // A second completed booking for the SAME customer.
        $secondBooking = \App\Models\Booking::create([
            'code' => 'TST2-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $referred->id, 'service_id' => $firstBooking->service_id, 'address_id' => $address->id,
            'status' => 'completed', 'price_quoted' => 500, 'payment_status' => 'paid', 'payment_method' => 'online',
        ]);

        $result = app(ReferralService::class)->qualifyFromCompletedBooking($secondBooking);

        $this->assertNull($result, 'A referral already rewarded (pending guard consumed) must not qualify again.');
        $this->assertEquals(50.0, app(WalletService::class)->balance($referrer), 'Wallet must not be credited twice.');
    }

    public function test_a_customer_with_no_referral_qualifying_is_a_no_op(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('completed');

        $result = app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertNull($result);
    }
}
