<?php

namespace Tests\Feature\Referral;

use App\Actions\CreateBookingAction;
use App\Models\Booking;
use App\Models\Setting;
use App\Services\ReferralBalanceService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner decision 2026-10-17 — referral balance pays at most referral.balance_max_use_percent % of a booking,
 * the rest online (Razorpay) only; never with cash, never combined with the customer's own wallet. A
 * cancelled booking returns the referral part to the referral balance, once.
 */
class ReferralBalanceUseTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(float $price = 1500, float $referral = 1000, ?string $percent = '20'): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => $price]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Queue::fake();
        Setting::set('payment.wallet_enabled', '1');
        $percent === null ? Setting::clear('referral.balance_max_use_percent', 'global', null) : Setting::set('referral.balance_max_use_percent', $percent);
        if ($referral > 0) {
            app(ReferralBalanceService::class)->credit($customer, $referral, 'referral reward', 'seed:'.$customer->id);
        }

        return compact('franchise', 'zone', 'service', 'customer', 'address');
    }

    private function book(array $w, string $method, bool $useReferral = true): Booking
    {
        return $this->actingAs($w['customer'])->app->make(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => $method,
            'use_referral_balance' => $useReferral,
        ]);
    }

    private function referral($user): float
    {
        return app(ReferralBalanceService::class)->available($user);
    }

    public function test_owner_example_twenty_percent_of_1500_from_referral_rest_online(): void
    {
        $w = $this->world();

        $booking = $this->book($w, 'online');

        $this->assertEqualsWithDelta(300.0, (float) $booking->referral_balance_used, 0.001);
        $this->assertEqualsWithDelta(1200.0, $booking->amountPayable(), 0.001, 'Razorpay collects only the rest');
        $this->assertEqualsWithDelta(1500.0, (float) $booking->price_quoted, 0.001, 'gross price unchanged: commission base untouched');
        $this->assertEqualsWithDelta(700.0, $this->referral($w['customer']), 0.001);
        $this->assertNotSame('paid', $booking->payment_status, 'waits for the Razorpay payment before dispatch');
    }

    public function test_a_small_balance_is_used_up_to_what_is_there(): void
    {
        $w = $this->world(1500, 100);

        $booking = $this->book($w, 'online');

        $this->assertEqualsWithDelta(100.0, (float) $booking->referral_balance_used, 0.001);
        $this->assertSame(0.0, $this->referral($w['customer']));
    }

    public function test_with_own_wallet_it_is_refused_and_nothing_is_created_or_spent(): void
    {
        $w = $this->world();
        app(WalletService::class)->credit($w['customer'], 5000, 'top up', 'topup:test');

        try {
            $this->book($w, 'wallet');
            $this->fail('referral balance must never combine with the own wallet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('online', $e->getMessage());
        }

        $this->assertSame(0, Booking::count());
        $this->assertEqualsWithDelta(1000.0, $this->referral($w['customer']), 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) app(WalletService::class)->balance($w['customer']), 0.001);
    }

    public function test_with_cash_it_is_refused(): void
    {
        $w = $this->world();

        $this->expectException(\RuntimeException::class);
        $this->book($w, 'cash');
    }

    public function test_blank_percent_means_the_balance_cannot_be_spent(): void
    {
        $w = $this->world(1500, 1000, null);

        try {
            $this->book($w, 'online');
            $this->fail('blank percent must refuse');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Booking::count());
        $this->assertEqualsWithDelta(1000.0, $this->referral($w['customer']), 0.001);
    }

    public function test_without_the_option_nothing_is_taken(): void
    {
        $w = $this->world();

        $booking = $this->book($w, 'online', useReferral: false);

        $this->assertSame(0.0, (float) $booking->referral_balance_used);
        $this->assertEqualsWithDelta(1000.0, $this->referral($w['customer']), 0.001);
    }

    public function test_cancelling_returns_the_referral_part_once(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');

        $booking->forceFill(['status' => 'cancelled'])->save();
        $booking->forceFill(['status' => 'searching_provider'])->saveQuietly();
        $booking->forceFill(['status' => 'cancelled'])->save();

        $this->assertEqualsWithDelta(1000.0, $this->referral($w['customer']), 0.001);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($w['customer']), 'never returned to the wallet');
    }

    public function test_a_booking_that_used_referral_balance_can_never_switch_to_cash(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');

        $this->expectException(\Throwable::class);
        $booking->update(['payment_method' => 'cash']);
    }

    public function test_hundred_percent_covering_everything_marks_the_booking_paid(): void
    {
        $w = $this->world(500, 1000, '100');

        $booking = $this->book($w, 'online');

        $this->assertSame(0.0, $booking->amountPayable());
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertEqualsWithDelta(500.0, $this->referral($w['customer']), 0.001);
    }

    public function test_api_booking_can_use_the_referral_balance(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'use_referral_balance' => true,
        ])->assertStatus(201);

        $this->assertEqualsWithDelta(300.0, (float) Booking::firstOrFail()->referral_balance_used, 0.001);
    }
}
