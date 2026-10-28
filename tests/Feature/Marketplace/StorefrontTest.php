<?php

namespace Tests\Feature\Marketplace;

use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Shop\Checkout;
use App\Livewire\Customer\Shop\Home;
use App\Livewire\Customer\Shop\OrderShow;
use App\Livewire\Customer\Shop\ProductShow;
use App\Models\CartItem;
use App\Models\MarketplaceOrder;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\CartService;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 2 (customer storefront, 2026-10-29): /{city}/shop pages, the bag, checkout and the customer's
 * shop orders. Owner rules proven here: city-prefixed public pages that 404 while Commerce is off; nothing from an
 * unapproved shop; free delivery and discounts online-only (cash pays the fee, cash refused with a discount); the
 * customer pays today's price; cancel free before the shop accepts; the code shows only when it is needed.
 */
class StorefrontTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores', 'delivery_fee_flat' => 30, 'free_delivery_above_amount' => 150]);
        $product = $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 100, 'stock' => 3]);
        $customer = $this->makeCustomer();
        $this->w = compact('city', 'franchise', 'zone', 'store', 'product', 'customer') + ['address' => $this->makeAddress($customer, $franchise, $zone)];
    }

    private function bag(int $qty = 2): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['product'], $qty);
    }

    private function checkout()
    {
        return Livewire::actingAs($this->w['customer'])->test(Checkout::class, ['store' => $this->w['store']]);
    }

    // ── public pages ────────────────────────────────────────────────────

    public function test_the_shop_pages_live_under_the_city_and_show_only_approved_shops(): void
    {
        $this->get('/nellore/shop')->assertOk()->assertSee('Lakshmi Stores')->assertSee('Steel Bottle');
        $this->get('/nellore/shop/'.$this->w['store']->slug)->assertOk()->assertSee('free above')->assertSee('when paid online')->assertSee('Steel Bottle');
        $this->get('/nellore/shop/'.$this->w['store']->slug.'/steel-bottle')->assertOk()->assertSee('Add to bag')->assertSee('"@type":"Product"', false);

        $this->w['store']->update(['approval_status' => 'pending']);
        $this->get('/nellore/shop')->assertOk()->assertDontSee('Lakshmi Stores');
        $this->get('/nellore/shop/'.$this->w['store']->slug)->assertNotFound();
    }

    public function test_the_shop_is_not_there_while_commerce_is_off_for_the_city(): void
    {
        app(ModuleActivationService::class)->setActive(Modules::COMMERCE, 'franchise', $this->w['franchise']->id, false);
        $this->get('/nellore/shop')->assertNotFound();
        $this->get('/nellore/shop/'.$this->w['store']->slug)->assertNotFound();
    }

    public function test_a_guest_is_sent_to_sign_in_to_add_and_the_stepper_never_passes_the_stock(): void
    {
        Livewire::test(Home::class, ['city' => 'nellore'])->call('addToCart', $this->w['product']->id)->assertRedirect(route('customer.login'));

        $c = Livewire::actingAs($this->w['customer'])->test(ProductShow::class, ['city' => 'nellore', 'storeSlug' => $this->w['store']->slug, 'productSlug' => 'steel-bottle']);
        $c->call('addToCart', $this->w['product']->id)->call('addToCart', $this->w['product']->id)->call('addToCart', $this->w['product']->id)
            ->call('addToCart', $this->w['product']->id)->assertSet('cartMessage', 'Only 3 of Steel Bottle can be ordered.');
        $this->assertSame(3, (int) CartItem::where('user_id', $this->w['customer']->id)->value('quantity'));

        $c->call('removeOne', $this->w['product']->id)->call('removeOne', $this->w['product']->id)->call('removeOne', $this->w['product']->id);
        $this->assertSame(0, CartItem::where('user_id', $this->w['customer']->id)->count());
    }

    // ── checkout ────────────────────────────────────────────────────────

    public function test_cash_on_delivery_pays_the_delivery_fee_even_above_the_free_delivery_amount(): void
    {
        $this->bag(2); // 200, above the 150 free-delivery amount
        $this->checkout()->set('addressId', $this->w['address']->id)->set('paymentMethod', 'cash')
            ->assertViewHas('deliveryFee', 30.0)->call('placeOrder')->assertHasNoErrors();

        $order = MarketplaceOrder::sole();
        $this->assertEquals(30, $order->delivery_fee);
        $this->assertEquals(230, $order->total_amount);
        $this->assertSame('cash', $order->payment_method);
        $this->assertSame(0, CartItem::where('user_id', $this->w['customer']->id)->count(), 'bag emptied for this shop');
    }

    public function test_an_online_order_gets_free_delivery_and_opens_razorpay(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        $gateway->shouldReceive('createRawOrder')->once()->andReturn(['razorpay_order_id' => 'order_S1', 'amount' => 20000, 'currency' => 'INR', 'key_id' => 'rzp_test']);

        $this->bag(2);
        $this->checkout()->set('addressId', $this->w['address']->id)->set('paymentMethod', 'online')
            ->assertViewHas('deliveryFee', 0.0)->call('placeOrder')->assertDispatched('razorpay-open');

        $order = MarketplaceOrder::sole();
        $this->assertEquals(0, $order->delivery_fee);
        $this->assertTrue($order->isAwaitingOnlinePayment());
    }

    public function test_a_discounted_item_makes_cash_unavailable(): void
    {
        $this->w['product']->update(['discount_percent' => 10]);
        $this->bag(1);
        $c = $this->checkout()->assertSet('paymentMethod', 'online')->assertViewHas('cashBlocked', true)
            ->set('orderType', 'pickup')->set('paymentMethod', 'cash')->call('placeOrder');

        $c->assertSet('error', 'Discounted items must be paid online. Please choose online payment or wallet.');
        $this->assertSame(0, MarketplaceOrder::count());
    }

    public function test_the_customer_pays_todays_price_not_the_price_when_they_added_it(): void
    {
        $this->bag(1);
        $this->w['product']->update(['price' => 140]);
        $this->checkout()->set('orderType', 'pickup')->set('paymentMethod', 'cash')->call('placeOrder');
        $this->assertEquals(140, MarketplaceOrder::sole()->subtotal);
    }

    public function test_delivery_only_to_the_customers_own_address_in_the_shops_city(): void
    {
        $this->bag(1);
        $someoneElse = $this->makeAddress($this->makeCustomer(), $this->w['franchise'], $this->w['zone']);
        $this->checkout()->set('addressId', $someoneElse->id)->set('paymentMethod', 'cash')->call('placeOrder')
            ->assertSet('error', 'Choose where to deliver, or pick it up at the shop.');

        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $far = $this->makeAddress($this->w['customer'], $otherFranchise, $otherZone);
        $this->checkout()->set('addressId', $far->id)->set('paymentMethod', 'cash')->call('placeOrder')
            ->assertSet('error', 'This shop does not deliver to that address. Choose another address or pick it up.');
        $this->assertSame(0, MarketplaceOrder::count());
    }

    // ── the order page ──────────────────────────────────────────────────

    public function test_cancel_before_the_shop_accepts_is_free_and_refunds_the_wallet_in_full(): void
    {
        Setting::create(['key' => 'payment.wallet_enabled', 'value' => '1']);
        Setting::create(['key' => 'cancellation.free_minutes', 'value' => '0']);
        Setting::create(['key' => 'cancellation.fee_type', 'value' => 'flat']);
        Setting::create(['key' => 'cancellation.fee_value', 'value' => '50']);
        Wallet::updateOrCreate(['user_id' => $this->w['customer']->id], ['balance' => 500]);
        $this->bag(1);
        $this->checkout()->set('orderType', 'pickup')->set('paymentMethod', 'wallet')->call('placeOrder');
        $order = MarketplaceOrder::sole();
        $this->assertEquals(400, app(WalletService::class)->balance($this->w['customer']));

        Livewire::actingAs($this->w['customer'])->test(OrderShow::class, ['order' => $order])
            ->set('cancelReason', 'Ordered by mistake')->call('cancel');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEquals(0, $order->fresh()->cancellation_fee);
        $this->assertEquals(500, app(WalletService::class)->balance($this->w['customer']));
    }

    public function test_the_customer_cannot_cancel_once_packing_has_started(): void
    {
        $this->bag(1);
        $this->checkout()->set('orderType', 'pickup')->set('paymentMethod', 'cash')->call('placeOrder');
        $order = MarketplaceOrder::sole();
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted');
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'preparing');

        Livewire::actingAs($this->w['customer'])->test(OrderShow::class, ['order' => $order])
            ->set('cancelReason', 'Too slow')->call('cancel')->assertSet('flashType', 'error');
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_the_collection_code_shows_only_when_ready_and_only_to_the_customer_then_one_rating(): void
    {
        $this->bag(1);
        $this->checkout()->set('orderType', 'pickup')->set('paymentMethod', 'cash')->call('placeOrder');
        $order = MarketplaceOrder::sole();
        $page = fn () => Livewire::actingAs($this->w['customer'])->test(OrderShow::class, ['order' => $order->fresh()]);

        $page()->assertViewHas('showCode', false);
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(AdvanceMarketplaceOrderAction::class)->execute($order->id, $to);
        }
        $page()->assertViewHas('showCode', true)->assertSee($order->fresh()->delivery_otp);

        Livewire::actingAs($this->makeCustomer())->test(OrderShow::class, ['order' => $order])->assertNotFound();

        app(CompleteMarketplaceOrderAction::class)->execute($order->id);
        $page()->set('rating', 5)->set('comment', 'Quick and friendly')->call('rate')->call('rate');
        $this->assertSame(1, Review::where('marketplace_order_id', $order->id)->count());
        $this->get('/nellore/shop/'.$this->w['store']->slug)->assertSee('★ 5.0', false);
    }

    public function test_the_header_offers_the_shop_where_it_is_on(): void
    {
        $this->get('/nellore/shop')->assertOk()->assertSee(route('customer.shop.home', 'nellore'), false);
    }
}
