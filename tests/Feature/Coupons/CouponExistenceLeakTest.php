<?php

namespace Tests\Feature\Coupons;

use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Checkout;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Setting;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 verify item 1 — on a cash payment the answer for a real active code, a nonexistent code and a paused code
 * is identical (text and shape) on every surface, so a coupon's existence is never revealed. Cash is judged
 * before the code is ever looked up. (The cart has no payment method of its own: it previews as online.)
 */
class CouponExistenceLeakTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private const ONLINE_ONLY = 'Offers apply on online payment only.';

    private const GENERIC = 'This coupon cannot be applied to this order.';

    /** code => what it is. */
    private const CODES = ['REALCODE' => 'active', 'NOSUCHCODE' => 'nonexistent', 'PAUSEDCODE' => 'paused'];

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
        Setting::set('coupons.rate_limit.customer_attempts', '1000');
        Setting::set('coupons.rate_limit.ip_attempts', '1000');

        foreach (['REALCODE' => 'active', 'PAUSEDCODE' => 'paused'] as $code => $status) {
            $coupon = Coupon::create([
                'code' => $code, 'name' => $code, 'status' => $status, 'is_active' => $status === 'active', 'module' => 'service',
                'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 5,
            ]);
            CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);
        }

        return compact('franchise', 'zone', 'service', 'service2', 'customer', 'address');
    }

    private function api(array $w, string $code, string $method): array
    {
        $res = $this->actingAs($w['customer'], 'sanctum')->postJson('/api/coupons/validate', [
            'address_id' => $w['address']->id, 'payment_method' => $method, 'coupon_code' => $code, 'surface' => 'wizard',
            'services' => [['service_id' => $w['service']->id, 'quantity' => 1]],
        ]);

        return [$res->status(), $res->json('data')];
    }

    /** What a Livewire screen tells the customer about a code, reduced to comparable facts. */
    private function fingerprint($component): array
    {
        $html = $component->html();

        return [
            substr_count($html, self::ONLINE_ONLY) > 0,
            substr_count($html, self::GENERIC) > 0,
            substr_count($html, 'Discount'),
            substr_count($html, 'You pay'),
        ];
    }

    public function test_api_validate_on_cash_is_identical_for_active_nonexistent_and_paused_codes(): void
    {
        $w = $this->world();

        $answers = [];
        foreach (self::CODES as $code => $kind) {
            $answers[$kind] = $this->api($w, $code, 'cash');
        }

        $this->assertSame($answers['active'], $answers['nonexistent']);
        $this->assertSame($answers['active'], $answers['paused']);
        $this->assertSame(self::ONLINE_ONLY, $answers['active'][1]['message']);
        $this->assertEquals(0.0, $answers['active'][1]['discount']);
    }

    public function test_api_booking_on_cash_is_identical_for_the_three_codes(): void
    {
        $w = $this->world();
        Queue::fake();

        $answers = [];
        foreach (self::CODES as $code => $kind) {
            $res = $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
                'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'cash', 'coupon_code' => $code,
            ]);
            $answers[$kind] = [$res->status(), $res->json()];
        }

        $this->assertSame($answers['active'], $answers['nonexistent']);
        $this->assertSame($answers['active'], $answers['paused']);
        $this->assertSame(409, $answers['active'][0]);
        $this->assertSame(self::ONLINE_ONLY, $answers['active'][1]['message']);
        $this->assertSame(0, Booking::count());
    }

    public function test_api_bundle_on_cash_is_identical_for_the_three_codes(): void
    {
        $w = $this->world();
        Queue::fake();

        $answers = [];
        foreach (self::CODES as $code => $kind) {
            $res = $this->actingAs($w['customer'], 'sanctum')->postJson('/api/booking-bundles', [
                'payment_method' => 'cash', 'coupon_code' => $code,
                'services' => [
                    ['service_id' => $w['service']->id, 'address_id' => $w['address']->id],
                    ['service_id' => $w['service2']->id, 'address_id' => $w['address']->id],
                ],
            ]);
            $answers[$kind] = [$res->status(), $res->json()];
        }

        $this->assertSame($answers['active'], $answers['nonexistent']);
        $this->assertSame($answers['active'], $answers['paused']);
        $this->assertSame(self::ONLINE_ONLY, $answers['active'][1]['message']);
        $this->assertSame(0, BookingBundle::count());
    }

    public function test_wizard_on_cash_shows_the_same_text_for_the_three_codes(): void
    {
        $w = $this->world();

        $shown = [];
        foreach (self::CODES as $code => $kind) {
            $c = Livewire::actingAs($w['customer'])->test(Wizard::class, ['service' => $w['service']])
                ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next')
                ->set('paymentMethod', 'cash')->set('couponCode', $code)->call('applyCoupon');
            $shown[$kind] = $this->fingerprint($c);

            $c->call('placeBooking');
            $shown[$kind][] = $c->get('error');
        }

        $this->assertSame($shown['active'], $shown['nonexistent']);
        $this->assertSame($shown['active'], $shown['paused']);
        $this->assertTrue($shown['active'][0], 'The online-only sentence is shown.');
        $this->assertFalse($shown['active'][1], 'The generic sentence is not shown on cash.');
        $this->assertSame(0, Booking::count());
    }

    public function test_checkout_on_cash_shows_the_same_text_for_the_three_codes(): void
    {
        $w = $this->world();
        app(ServiceCartService::class)->add($w['customer'], $w['service']);
        app(ServiceCartService::class)->add($w['customer'], $w['service2']);

        $shown = [];
        foreach (self::CODES as $code => $kind) {
            $c = Livewire::actingAs($w['customer'])->test(Checkout::class)
                ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next')
                ->set('paymentMethod', 'cash')->set('couponCode', $code)->call('applyCoupon');
            $shown[$kind] = $this->fingerprint($c);

            $c->call('place');
            $shown[$kind][] = $c->get('error');
        }

        $this->assertSame($shown['active'], $shown['nonexistent']);
        $this->assertSame($shown['active'], $shown['paused']);
        $this->assertTrue($shown['active'][0]);
        $this->assertSame(0, BookingBundle::count());
    }

    public function test_a_paused_code_online_looks_exactly_like_a_nonexistent_one(): void
    {
        $w = $this->world();

        $paused = $this->api($w, 'PAUSEDCODE', 'online');
        $missing = $this->api($w, 'NOSUCHCODE', 'online');

        $this->assertSame($missing, $paused);
        $this->assertSame(self::GENERIC, $paused[1]['message']);
    }
}
