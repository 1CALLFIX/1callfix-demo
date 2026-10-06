<?php

namespace Tests\Feature\Coupons;

use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Cart\Index as CartIndex;
use App\Livewire\Customer\Checkout;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 item 2 — the customer coupon field on the wizard, cart, checkout and bundles, plus the validate API for
 * Flutter. Everything shown (full price, discount, payable) is server-computed; online-only goes through the one
 * OnlinePaymentGuard; no internal reason ever reaches the customer.
 */
class CouponEntrySurfacesTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private const NOTE = 'Visit and inspection charges are separate from coupon discounts.';

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $service = $this->makeService($category, ['base_price' => 500]);
        $service2 = $this->makeService($category, ['base_price' => 300]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        Setting::set('payment.wallet_enabled', '1');

        $coupon = Coupon::create([
            'code' => 'SAVE100', 'name' => 'Save 100', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 5,
        ]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);

        return compact('franchise', 'zone', 'service', 'service2', 'customer', 'address');
    }

    private function validatePayload(array $w, array $extra = []): array
    {
        return array_merge([
            'address_id' => $w['address']->id,
            'services' => [['service_id' => $w['service']->id, 'quantity' => 1]],
            'payment_method' => 'online',
            'coupon_code' => 'SAVE100',
            'surface' => 'wizard',
        ], $extra);
    }

    private function validateApi(array $w, array $extra = [])
    {
        return $this->actingAs($w['customer'], 'sanctum')->postJson('/api/coupons/validate', $this->validatePayload($w, $extra));
    }

    private function atPayStep(array $w)
    {
        return Livewire::actingAs($w['customer'])->test(Wizard::class, ['service' => $w['service']])
            ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next');
    }

    // ---------------------------------------------------------------- validate API

    public function test_validate_online_returns_server_computed_full_price_discount_and_payable(): void
    {
        $w = $this->world();

        $res = $this->validateApi($w)->assertOk()->json('data');

        $this->assertTrue($res['eligible']);
        $this->assertEquals(500.0, $res['full_price']);
        $this->assertEquals(100.0, $res['discount']);
        $this->assertEquals(400.0, $res['payable']);
        $this->assertSame(self::NOTE, $res['note']);
    }

    public function test_validate_on_cash_is_rejected_with_the_online_only_sentence_and_shows_the_full_price(): void
    {
        $w = $this->world();

        $res = $this->validateApi($w, ['payment_method' => 'cash'])->assertOk()->json('data');

        $this->assertFalse($res['eligible']);
        $this->assertSame('Offers apply on online payment only.', $res['message']);
        $this->assertEquals(0.0, $res['discount']);
        $this->assertEquals(500.0, $res['payable']);
    }

    public function test_validate_never_leaks_an_internal_reason_for_any_rejection(): void
    {
        $w = $this->world();
        Coupon::where('code', 'SAVE100')->update(['status' => 'expired']);

        foreach (['SAVE100', 'NOPE', 'save 100'] as $code) {
            $json = $this->validateApi($w, ['coupon_code' => $code])->assertOk()->json('data');

            $this->assertFalse($json['eligible']);
            $this->assertSame('This coupon cannot be applied to this order.', $json['message']);
            $this->assertEqualsCanonicalizing(['eligible', 'message', 'full_price', 'subtotal', 'discount', 'payable', 'note'], array_keys($json));
        }
        $raw = $this->validateApi($w, ['coupon_code' => 'NOPE'])->getContent();
        foreach (['reason', 'detail', 'CouponException', 'expired', 'invalid_code'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }
    }

    public function test_a_flash_sale_service_quotes_the_full_price_separately_from_the_offer(): void
    {
        $w = $this->world();
        $this->makeFlashSale([$w['service']], ['discount_type' => 'percent', 'discount_value' => 20]);
        Coupon::where('code', 'SAVE100')->update(['stackable_with_flash' => true]);

        $res = $this->validateApi($w)->assertOk()->json('data');

        $this->assertEquals(500.0, $res['full_price']);
        $this->assertEquals(400.0, $res['subtotal']);
        $this->assertEquals(300.0, $res['payable']);
    }

    public function test_a_forged_client_amount_cannot_change_the_quote(): void
    {
        $w = $this->world();

        $res = $this->validateApi($w, ['discount' => 499, 'payable' => 1, 'full_price' => 1, 'price' => 1])->assertOk()->json('data');

        $this->assertEquals(100.0, $res['discount']);
        $this->assertEquals(400.0, $res['payable']);
    }

    public function test_the_validate_endpoint_needs_a_logged_in_customer_and_a_surface_that_is_on(): void
    {
        $w = $this->world();
        $this->postJson('/api/coupons/validate', $this->validatePayload($w))->assertUnauthorized();

        Setting::set('coupons.surface.wizard', '0');
        $this->validateApi($w)->assertStatus(422)->assertJsonPath('message', 'This coupon cannot be applied to this order.');

        Setting::set('coupons.surface.wizard', '1');
        Setting::set('coupons.enabled', '0');
        $this->validateApi($w)->assertStatus(422);
    }

    public function test_attempts_are_rate_limited_per_customer_and_per_ip_from_the_settings(): void
    {
        $w = $this->world();
        Setting::set('coupons.rate_limit.customer_attempts', '2');
        Setting::set('coupons.rate_limit.window_seconds', '60');
        RateLimiter::clear('coupon:customer:'.$w['customer']->id);

        $this->validateApi($w)->assertOk();
        $this->validateApi($w)->assertOk();
        $this->validateApi($w)->assertStatus(429);

        // A different customer on the same IP is limited by the per-IP bucket instead.
        Setting::set('coupons.rate_limit.customer_attempts', '50');
        Setting::set('coupons.rate_limit.ip_attempts', '1');
        $other = $this->makeCustomer();
        $this->actingAs($other, 'sanctum')->postJson('/api/coupons/validate', $this->validatePayload($w))->assertStatus(429);
    }

    // ---------------------------------------------------------------- booking APIs

    public function test_api_booking_with_a_coupon_applies_it_online_and_rejects_cash(): void
    {
        $w = $this->world();
        Queue::fake();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'coupon_code' => 'SAVE100',
        ])->assertStatus(201);
        $this->assertEquals(100.0, (float) Booking::latest('id')->first()->coupon_discount_amount);

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'cash', 'coupon_code' => 'SAVE100',
        ])->assertStatus(409)->assertJsonPath('message', 'Offers apply on online payment only.');
        $this->assertSame(1, Booking::count(), 'The rejected cash booking created nothing.');
    }

    public function test_api_bundle_with_a_coupon_applies_it_and_obeys_the_bundles_toggle(): void
    {
        $w = $this->world();
        Queue::fake();
        $body = [
            'payment_method' => 'online', 'coupon_code' => 'SAVE100',
            'services' => [
                ['service_id' => $w['service']->id, 'address_id' => $w['address']->id],
                ['service_id' => $w['service2']->id, 'address_id' => $w['address']->id],
            ],
        ];

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/booking-bundles', $body)->assertStatus(201);
        $this->assertNotNull(BookingBundle::firstOrFail()->coupon_id);

        Setting::set('coupons.surface.bundles', '0');
        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/booking-bundles', $body)
            ->assertStatus(422)->assertJsonPath('message', 'This coupon cannot be applied to this order.');
        $this->assertSame(1, BookingBundle::count());
    }

    public function test_the_api_booking_coupon_field_obeys_the_wizard_toggle(): void
    {
        $w = $this->world();
        Setting::set('coupons.surface.wizard', '0');

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'coupon_code' => 'SAVE100',
        ])->assertStatus(422);
        $this->assertSame(0, Booking::count());
    }

    // ---------------------------------------------------------------- web: wizard

    public function test_wizard_shows_full_price_discount_and_payable_and_the_visit_charge_note(): void
    {
        $w = $this->world();

        $this->atPayStep($w)
            ->assertSee('Coupon code')
            ->set('couponCode', 'save100')->call('applyCoupon')
            ->assertSee('Discount')->assertSee('₹100.00')->assertSee('You pay')->assertSee('₹400.00')
            ->assertSee(self::NOTE);
    }

    public function test_wizard_on_cash_says_offers_apply_on_online_payment_only_and_will_not_book_with_the_code(): void
    {
        $w = $this->world();

        $this->atPayStep($w)
            ->set('couponCode', 'SAVE100')->set('paymentMethod', 'cash')->call('applyCoupon')
            ->assertSee('Offers apply on online payment only.')
            ->call('placeBooking');

        $this->assertSame(0, Booking::count());
    }

    public function test_wizard_places_a_wallet_booking_with_the_coupon(): void
    {
        $w = $this->world();
        Wallet::updateOrCreate(['user_id' => $w['customer']->id], ['balance' => 1000]);

        $this->atPayStep($w)->set('couponCode', 'SAVE100')->set('paymentMethod', 'wallet')->call('placeBooking')->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertEquals(100.0, (float) $booking->coupon_discount_amount);
        $this->assertEquals(400.0, $booking->amountPayable());
    }

    public function test_wizard_wrong_code_shows_only_the_generic_sentence(): void
    {
        $w = $this->world();

        $this->atPayStep($w)->set('couponCode', 'WRONG')->call('applyCoupon')
            ->assertSee('This coupon cannot be applied to this order.')->assertDontSee('not valid');
    }

    public function test_wizard_hides_the_field_when_its_surface_is_off(): void
    {
        $w = $this->world();
        Setting::set('coupons.surface.wizard', '0');

        $this->atPayStep($w)->assertDontSee('Coupon code');
    }

    // ---------------------------------------------------------------- web: cart and checkout

    public function test_cart_applies_a_coupon_and_shows_the_amounts(): void
    {
        $w = $this->world();
        app(ServiceCartService::class)->add($w['customer'], $w['service'], quantity: 2); // 1000

        Livewire::actingAs($w['customer'])->test(CartIndex::class)
            ->assertSee('Coupon code')
            ->set('couponCode', 'SAVE100')->call('applyCoupon')
            ->assertSee('Discount')->assertSee('₹100.00')->assertSee('₹900.00')->assertSee(self::NOTE);
    }

    public function test_cart_hides_the_field_when_its_surface_is_off(): void
    {
        $w = $this->world();
        app(ServiceCartService::class)->add($w['customer'], $w['service']);
        Setting::set('coupons.surface.cart', '0');

        Livewire::actingAs($w['customer'])->test(CartIndex::class)->assertDontSee('Coupon code');
    }

    public function test_checkout_places_a_bundle_with_the_coupon(): void
    {
        $w = $this->world();
        Wallet::updateOrCreate(['user_id' => $w['customer']->id], ['balance' => 5000]);
        $cart = app(ServiceCartService::class);
        $cart->add($w['customer'], $w['service']);
        $cart->add($w['customer'], $w['service2']);

        Livewire::actingAs($w['customer'])->test(Checkout::class)
            ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next')
            ->assertSee('Coupon code')
            ->set('couponCode', 'SAVE100')->call('applyCoupon')->assertSee('₹100.00')->assertSee('₹700.00')
            ->set('paymentMethod', 'wallet')->call('place')->assertHasNoErrors();

        $this->assertNotNull(BookingBundle::firstOrFail()->coupon_id);
    }

    public function test_checkout_on_cash_with_a_code_creates_no_bundle(): void
    {
        $w = $this->world();
        app(ServiceCartService::class)->add($w['customer'], $w['service']);
        app(ServiceCartService::class)->add($w['customer'], $w['service2']);

        Livewire::actingAs($w['customer'])->test(Checkout::class)
            ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next')
            ->set('couponCode', 'SAVE100')->set('paymentMethod', 'cash')->call('place');

        $this->assertSame(0, BookingBundle::count());
    }
}
