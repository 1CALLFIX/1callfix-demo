<?php

namespace Tests\Feature\Coupons;

use App\Actions\CreateBookingAction;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 item 4 — F1 acquisition data (source, campaign, UTM) survives a coupon booking. Applying a coupon rewrites
 * the price columns and the coupon snapshot; it must never touch `bookings.acquisition`.
 */
class CouponAcquisitionTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $a = $this->makeService($category, ['base_price' => 500]);
        $b = $this->makeService($category, ['base_price' => 300]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        $coupon = Coupon::create([
            'code' => 'SAVE100', 'name' => 'Save 100', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 5,
        ]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);
        Queue::fake();

        return compact('franchise', 'zone', 'a', 'b', 'customer', 'address');
    }

    public function test_a_web_session_coupon_booking_keeps_source_campaign_and_utm(): void
    {
        $w = $this->world();
        $this->get('/?utm_source=google&utm_medium=cpc&utm_campaign=ac-repair');

        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['a']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'coupon_code' => 'SAVE100',
        ])->fresh();

        $this->assertNotNull($booking->coupon_id, 'The coupon really applied.');
        $this->assertSame('google', $booking->acquisition['utm_source']);
        $this->assertSame('cpc', $booking->acquisition['utm_medium']);
        $this->assertSame('ac-repair', $booking->acquisition['utm_campaign']);
    }

    public function test_an_api_coupon_booking_keeps_its_acquisition(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['a']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'coupon_code' => 'SAVE100',
            'acquisition' => ['utm_source' => 'playstore', 'utm_campaign' => 'launch', 'utm_medium' => 'install_referrer'],
        ])->assertStatus(201);

        $booking = Booking::firstOrFail();
        $this->assertNotNull($booking->coupon_id);
        $this->assertSame('playstore', $booking->acquisition['utm_source']);
        $this->assertSame('launch', $booking->acquisition['utm_campaign']);
    }

    public function test_every_child_of_a_coupon_bundle_keeps_its_acquisition(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/booking-bundles', [
            'payment_method' => 'online', 'coupon_code' => 'SAVE100',
            'services' => [
                ['service_id' => $w['a']->id, 'address_id' => $w['address']->id],
                ['service_id' => $w['b']->id, 'address_id' => $w['address']->id],
            ],
            'acquisition' => ['utm_source' => 'google', 'utm_campaign' => 'summer', 'gclid' => 'g1'],
        ])->assertStatus(201);

        $this->assertNotNull(BookingBundle::firstOrFail()->coupon_id);
        $this->assertSame(2, Booking::count());
        foreach (Booking::all() as $child) {
            $this->assertSame('google', $child->acquisition['utm_source']);
            $this->assertSame('summer', $child->acquisition['utm_campaign']);
        }
    }

    public function test_the_coupon_does_not_change_the_acquisition_a_plain_booking_would_get(): void
    {
        $w = $this->world();
        $this->get('/?utm_source=google&utm_campaign=x');
        $base = [
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['a']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online',
        ];

        $plain = app(CreateBookingAction::class)->execute($base)->fresh();
        $coupon = app(CreateBookingAction::class)->execute($base + ['coupon_code' => 'SAVE100'])->fresh();

        $this->assertSame($plain->acquisition, $coupon->acquisition);
    }
}
