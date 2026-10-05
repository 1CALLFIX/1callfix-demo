<?php

namespace Tests\Feature\Coupons;

use App\Livewire\Customer\Bundles\Show as BundleShow;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 fixes item 1 — what a COUPON bundle actually sends to Razorpay (gateway mocked at the HTTP boundary), and
 * what the customer sees on /bundles/{id}. Gross 798, coupon 100: the order must be 698 rupees = 69800 paise,
 * and the page shows the discount and the payable.
 */
class CouponBundlePayAmountTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.razorpay.key_id' => 'rzp_test_bundlekey123',
            'services.razorpay.key_secret' => 'fake-bundle-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-bundle-webhook-secret-never-real',
        ]);
        Queue::fake();
    }

    private function couponBundle(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $a = $this->makeService($category, ['base_price' => 499]);
        $b = $this->makeService($category, ['base_price' => 299]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        $coupon = Coupon::create([
            'code' => 'SAVE100', 'name' => 'Save 100', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 5,
        ]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);

        $this->actingAs($customer, 'sanctum')->postJson('/api/booking-bundles', [
            'payment_method' => 'online', 'coupon_code' => 'SAVE100',
            'services' => [
                ['service_id' => $a->id, 'address_id' => $address->id],
                ['service_id' => $b->id, 'address_id' => $address->id],
            ],
        ])->assertStatus(201);

        return [BookingBundle::firstOrFail(), $customer];
    }

    private function fakeGateway(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_c1', 'amount' => 69800, 'currency' => 'INR'], 200)]);
    }

    public function test_the_api_create_order_sends_the_coupon_payable_in_paise(): void
    {
        [$bundle, $customer] = $this->couponBundle();
        $this->assertEquals(798.0, (float) $bundle->total_price_quoted);
        $this->assertEquals(100.0, (float) $bundle->coupon_discount_amount);
        $this->fakeGateway();

        $this->actingAs($customer, 'sanctum')->postJson("/api/booking-bundles/{$bundle->id}/pay/create-order")
            ->assertOk()->assertJsonPath('data.amount', 69800);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/orders') && (int) $r['amount'] === 69800);
        $this->assertEquals(698.0, (float) Payment::where('purpose', 'booking_bundle')->firstOrFail()->amount);
    }

    public function test_the_web_pay_button_opens_an_order_for_the_coupon_payable(): void
    {
        [$bundle, $customer] = $this->couponBundle();
        $this->fakeGateway();

        Livewire::actingAs($customer)->test(BundleShow::class, ['bundle' => $bundle])
            ->call('payNow')
            ->assertDispatched('bundle-pay-open', fn ($name, $params) => $params['order']['amount'] === 69800);

        Http::assertSent(fn (Request $r) => (int) $r['amount'] === 69800);
    }

    public function test_the_bundle_page_shows_the_discount_and_the_payable(): void
    {
        [$bundle, $customer] = $this->couponBundle();

        Livewire::actingAs($customer)->test(BundleShow::class, ['bundle' => $bundle])
            ->assertSee('Coupon discount')->assertSee('₹100.00')
            ->assertSee('You pay')->assertSee('₹698.00')
            ->assertSee('₹798.00');
    }

    public function test_a_bundle_without_a_coupon_shows_no_discount_row(): void
    {
        [$bundle, $customer] = $this->couponBundle();
        $bundle->forceFill(['coupon_id' => null, 'coupon_discount_amount' => 0])->save();

        Livewire::actingAs($customer)->test(BundleShow::class, ['bundle' => $bundle->fresh()])
            ->assertDontSee('Coupon discount')->assertSee('₹798.00');
    }
}
