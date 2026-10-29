<?php

namespace Tests\Feature\Marketplace;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Livewire\Customer\Shop\Checkout;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce: coupons on shop orders (engine connected for 'commerce', 2026-10-29). Thumb rule: a coupon is a
 * benefit, so cash is refused server-side. The coupon is reserved with the order, confirmed on completion and
 * released on cancel; it is HQ-funded, so the seller still earns on the full price.
 */
class ShopCouponTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $franchise->update(['platform_fee_percent' => 20, 'commission_model' => 'none', 'commission_value' => 0]);
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 500, 'stock' => 10]);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        $coupon = Coupon::create([
            'code' => 'SHOP50', 'name' => 'Shop 50', 'status' => 'active', 'is_active' => true, 'module' => 'commerce',
            'discount_type' => 'flat', 'value' => 50, 'min_order_value' => 0, 'per_user_limit' => 5,
        ]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);
        $this->w = compact('franchise', 'zone', 'store', 'product', 'coupon') + ['customer' => $this->makeCustomer()];
    }

    private function order(string $method, ?string $code = 'SHOP50'): MarketplaceOrder
    {
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 1);

        return app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->w['store']->id, 'customer_id' => $this->w['customer']->id, 'order_type' => 'pickup',
            'payment_method' => $method, 'coupon_code' => $code,
        ]);
    }

    public function test_a_shop_coupon_takes_money_off_an_online_order_and_is_reserved(): void
    {
        $order = $this->order('online');
        $this->assertEquals(50, $order->coupon_discount_amount);
        $this->assertEquals(450, $order->total_amount);
        $this->assertTrue($order->usesBenefit());
        $this->assertSame('reserved', CouponUsage::where('marketplace_order_id', $order->id)->value('status'));
    }

    public function test_cash_is_refused_with_a_coupon(): void
    {
        $this->expectExceptionMessage(\App\Exceptions\CouponException::ONLINE_ONLY_MESSAGE);
        $this->order('cash');
    }

    public function test_a_service_coupon_does_not_work_in_the_shop(): void
    {
        $this->w['coupon']->update(['module' => 'service']);
        $this->expectException(\RuntimeException::class);
        $this->order('online');
    }

    public function test_completion_confirms_the_coupon_and_the_platform_carries_the_discount(): void
    {
        Setting::create(['key' => 'payment.wallet_enabled', 'value' => '1']);
        Wallet::updateOrCreate(['user_id' => $this->w['customer']->id], ['balance' => 1000]);
        $order = $this->order('wallet');
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(\App\Actions\AdvanceMarketplaceOrderAction::class)->execute($order->id, $to);
        }
        app(CompleteMarketplaceOrderAction::class)->execute($order->id);

        $this->assertSame('confirmed', CouponUsage::where('marketplace_order_id', $order->id)->value('status'));
        $row = MarketplaceSettlement::where('marketplace_order_id', $order->id)->sole();
        $this->assertEquals(500, $row->goods_amount, 'full goods value');
        $this->assertEquals(400, $row->seller_share, '80% of 500: the seller is not charged the coupon');
        $this->assertEquals(50, $row->goods_platform, '100 commission - 50 coupon');
    }

    public function test_a_cancelled_order_gives_the_coupon_back(): void
    {
        $order = $this->order('online');
        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'Customer changed their mind');
        $this->assertSame('released', CouponUsage::where('marketplace_order_id', $order->id)->value('status'));
    }

    public function test_checkout_applies_and_drops_the_coupon_when_switching_to_cash(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 1);
        Livewire::actingAs($this->w['customer'])->test(Checkout::class, ['store' => $this->w['store']])
            ->set('orderType', 'pickup')->set('couponInput', 'shop50')->call('applyCoupon')
            ->assertSet('appliedCoupon', 'SHOP50')->assertViewHas('total', 450.0)->assertViewHas('cashBlocked', true)
            ->set('paymentMethod', 'cash')->assertSet('appliedCoupon', '')->assertViewHas('total', 500.0);
    }
}
