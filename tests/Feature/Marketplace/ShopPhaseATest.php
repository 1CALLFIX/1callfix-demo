<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Customer\Shop\Bag;
use App\Livewire\Customer\Shop\Checkout;
use App\Livewire\Customer\Shop\ProductShow;
use App\Models\CartItem;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\GuestShopCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Shop Phase A 2026-11-02 (owner, after walking Amazon, Flipkart and Meesho end to end):
 *  - a visitor fills a cart without signing in; it joins their own cart the moment they sign in;
 *  - "Add to Cart" + "Buy now" (straight to checkout; a visitor signs in first);
 *  - a missing size is marked in red on the size itself (Meesho);
 *  - the delivery promise ("Delivered by about 6:30 PM today") from Shop Settings;
 *  - the price box: price, offer, delivery, total and "You save".
 */
class ShopPhaseATest extends TestCase
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
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores', 'delivery_fee_flat' => 30, 'free_delivery_above_amount' => 400]);
        $bottle = $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 200, 'discount_percent' => 10, 'stock' => 5]);
        $customer = $this->makeCustomer();
        $this->w = compact('franchise', 'zone', 'store', 'bottle', 'customer');
    }

    private function page()
    {
        return Livewire::test(ProductShow::class, ['city' => 'nellore', 'storeSlug' => $this->w['store']->slug, 'productSlug' => $this->w['bottle']->slug]);
    }

    public function test_a_visitor_fills_a_cart_and_it_joins_their_cart_at_sign_in(): void
    {
        $this->page()->call('addToCart', $this->w['bottle']->id)->call('addToCart', $this->w['bottle']->id)->assertSee('2 in cart');
        $this->assertSame(2, app(GuestShopCart::class)->count());
        $this->assertSame(0, CartItem::count());

        // The visitor's cart page: the item, the saving, and "Sign in to checkout".
        Livewire::test(Bag::class)->assertSee('Steel Bottle')->assertSee('You save ₹40.00 with online payment')
            ->assertSee('Sign in to checkout')->call('checkout', $this->w['store']->id)->assertRedirect(route('customer.login'));
        $this->assertSame(route('customer.shop.checkout', $this->w['store']->id), session('url.intended'));

        // Already 1 in their own cart from another day: the two join, within the stock (5).
        app(CartService::class)->add($this->w['customer'], $this->w['bottle'], 1);
        Auth::login($this->w['customer']);

        $this->assertSame(3, (int) CartItem::where('user_id', $this->w['customer']->id)->value('quantity'));
        $this->assertSame(0, app(GuestShopCart::class)->count(), 'the session copy is cleared');
    }

    public function test_the_joined_cart_never_passes_the_stock(): void
    {
        app(GuestShopCart::class)->set($this->w['bottle']->id, null, 4);
        app(CartService::class)->add($this->w['customer'], $this->w['bottle'], 3);
        Auth::login($this->w['customer']);

        $this->assertSame(5, (int) CartItem::where('user_id', $this->w['customer']->id)->value('quantity'));
    }

    public function test_the_cart_page_opens_for_a_visitor(): void
    {
        app(GuestShopCart::class)->set($this->w['bottle']->id, null, 1);

        $this->get(route('customer.cart'))->assertOk()->assertSee('Steel Bottle')->assertSee('Sign in to checkout');
        $this->get(route('customer.shop.checkout', $this->w['store']->id))->assertRedirect(); // checkout still needs sign-in
    }

    public function test_buy_now_goes_straight_to_checkout_and_a_visitor_signs_in_first(): void
    {
        $this->page()->call('buyNow')->assertRedirect(route('customer.login'));
        $this->assertSame(1, app(GuestShopCart::class)->quantityFor($this->w['bottle']->id));
        $this->assertSame(route('customer.shop.checkout', $this->w['store']->id), session('url.intended'));

        Livewire::actingAs($this->w['customer'])->test(ProductShow::class, ['city' => 'nellore', 'storeSlug' => $this->w['store']->slug, 'productSlug' => $this->w['bottle']->slug])
            ->assertSee('Add to Cart')->assertSee('Buy now')->assertSee('Inclusive of all taxes')->assertSee('10% off')
            ->call('buyNow')->assertRedirect(route('customer.shop.checkout', $this->w['store']->id));
        $this->assertSame(1, (int) CartItem::where('user_id', $this->w['customer']->id)->value('quantity'), 'Buy now adds one, never doubles');
    }

    public function test_a_missing_size_is_marked_on_the_size_and_low_stock_shows_under_it(): void
    {
        $this->w['bottle']->update(['option_names' => ['Size']]);
        $this->w['bottle']->variants()->create(['name' => '500 ml', 'options' => ['Size' => '500 ml'], 'stock' => 2, 'is_active' => true]);
        $this->w['bottle']->variants()->create(['name' => '1 L', 'options' => ['Size' => '1 L'], 'stock' => 9, 'price_override' => 300, 'is_active' => true]);

        $this->page()->assertSee('2 left')
            ->call('buyNow')->assertSet('missingChoice', 'Size')->assertSee('Please select a size')->assertNoRedirect()
            ->call('pick', 'Size', '1 L')->assertSet('missingChoice', '')->assertSee('₹270.00');
    }

    public function test_the_delivery_promise_comes_from_shop_settings(): void
    {
        $this->page()->assertDontSee('Delivered by about');

        Setting::updateOrCreate(['key' => 'marketplace.delivery_promise_minutes', 'scope_type' => 'global', 'scope_id' => null], ['value' => '45']);
        $this->page()->assertSee('Delivered by about');

        $this->w['store']->update(['is_open' => false]);
        $this->page()->assertDontSee('Delivered by about');
    }

    public function test_the_price_box_shows_the_offer_free_delivery_and_the_saving(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['bottle'], 3); // 3 x 200 = 600, offer 540
        $this->makeAddress($this->w['customer'], $this->w['franchise'], $this->w['zone']);

        Livewire::actingAs($this->w['customer'])->test(Checkout::class, ['store' => $this->w['store']])
            ->assertSee('Price details')->assertSee('Price (3 items)')->assertSee('₹600.00')->assertSee('Offer discount')
            ->assertSee('−₹60.00')->assertViewHas('total', 540.0)
            ->assertSee('You save ₹90.00 on this order') // ₹60 offer + ₹30 free delivery
            ->assertSee('Pay ₹540.00')
            ->set('paymentMethod', 'cash')->assertViewHas('total', 630.0)->assertSee('Place order');
    }
}
