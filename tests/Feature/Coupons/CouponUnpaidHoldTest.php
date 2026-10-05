<?php

namespace Tests\Feature\Coupons;

use App\Actions\CreateBookingAction;
use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Coupons\CouponHoldSweepService;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 item 3 — the unpaid-hold countdown. A coupon booking paid online must be paid within
 * coupons.unpaid_hold_minutes; the customer sees how long is left, and the sweep (existing) cancels and releases
 * the coupon when it runs out. Covers reservation, expiry, release, retry and payment success.
 */
class CouponUnpaidHoldTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');

        $coupon = Coupon::create([
            'code' => 'SAVE100', 'name' => 'Save 100', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 1,
        ]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);

        return compact('franchise', 'zone', 'service', 'customer', 'address', 'coupon');
    }

    private function book(array $w): Booking
    {
        Queue::fake();

        return app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'coupon_code' => 'SAVE100',
        ]);
    }

    private function page(array $w, Booking $booking)
    {
        return Livewire::actingAs($w['customer'])->test(OrderShow::class, ['booking' => $booking]);
    }

    public function test_reservation_shows_a_countdown_from_the_configured_hold_and_the_discounted_amount_due(): void
    {
        $w = $this->world();
        $booking = $this->book($w);

        $this->assertSame('reserved', CouponUsage::firstOrFail()->status);
        $this->page($w, $booking)
            ->assertSee('Complete payment within')
            ->assertSee('your coupon is released')
            ->assertSeeHtml('data-hold-seconds="1800"')
            ->assertSee('Coupon discount')->assertSee('₹400.00');
    }

    public function test_the_countdown_follows_the_clock_and_the_setting(): void
    {
        $w = $this->world();
        $booking = $this->book($w);

        $this->travel(10)->minutes();
        $this->page($w, $booking)->assertSeeHtml('data-hold-seconds="1200"');

        Setting::set('coupons.unpaid_hold_minutes', '45');
        $this->page($w, $booking)->assertSeeHtml('data-hold-seconds="2100"');
    }

    public function test_expiry_cancels_releases_the_coupon_and_the_countdown_disappears(): void
    {
        $w = $this->world();
        $booking = $this->book($w);

        $this->travel(31)->minutes();
        $this->assertSame(1, app(CouponHoldSweepService::class)->sweep());

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('released', CouponUsage::firstOrFail()->status);
        $this->page($w, $booking->fresh())->assertDontSee('Complete payment within');
    }

    public function test_after_a_release_the_customer_can_retry_the_same_coupon(): void
    {
        $w = $this->world();
        $this->book($w);
        $this->assertSame('reserved', CouponUsage::firstOrFail()->status);

        $this->travel(31)->minutes();
        app(CouponHoldSweepService::class)->sweep();

        $retry = $this->book($w);

        $this->assertEquals(100.0, (float) $retry->coupon_discount_amount, 'per_user_limit 1 was given back by the release.');
        $this->assertSame(1, CouponUsage::where('status', 'reserved')->count());
    }

    public function test_payment_success_confirms_the_coupon_removes_the_countdown_and_the_sweep_leaves_it_alone(): void
    {
        $w = $this->world();
        $booking = $this->book($w);
        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => $booking->amountPayable(),
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_HOLD1', 'status' => 'pending',
        ]);

        app(RazorpayWebhookHandler::class)->handleCaptured(['payload' => ['payment' => ['entity' => [
            'id' => 'pay_hold1', 'order_id' => 'order_HOLD1', 'amount' => 40000, 'currency' => 'INR',
        ]]]]);

        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->page($w, $booking->fresh())->assertDontSee('Complete payment within');

        $this->travel(2)->hours();
        $this->assertSame(0, app(CouponHoldSweepService::class)->sweep());
        $this->assertNotSame('cancelled', $booking->fresh()->status);
        $this->assertNotSame('released', CouponUsage::firstOrFail()->status);
    }

    public function test_a_booking_without_a_coupon_has_no_countdown(): void
    {
        $w = $this->world();
        Queue::fake();
        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online',
        ]);

        $this->page($w, $booking)->assertDontSee('Complete payment within');
    }
}
