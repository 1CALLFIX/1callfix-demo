<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Customer\Cart\Index as CartIndex;
use App\Livewire\Customer\CartCount;
use App\Livewire\Customer\Shop\Bag;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\Customer\ServiceCartService;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** Owner 2026-10-30: one cart icon, one /cart page — a Services row and one box per shop, each with its own checkout. */
class OneCartTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores', 'delivery_fee_flat' => 30, 'free_delivery_above_amount' => 500]);
        $product = $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 100, 'stock' => 10]);
        [, $service] = $this->makeCategoryAndService();
        $this->w = compact('store', 'product', 'service') + ['customer' => $this->makeCustomer()];
    }

    public function test_one_cart_shows_the_services_row_and_each_shop_with_its_own_checkout(): void
    {
        app(ServiceCartService::class)->add($this->w['customer'], $this->w['service']);
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 2);

        Livewire::actingAs($this->w['customer'])->test(CartIndex::class)
            ->assertSee('data-testid="cart-services"', false)
            ->assertSee($this->w['service']->name)
            ->assertSee('Book services →')
            ->assertSee('data-testid="cart-shop"', false)
            ->assertDontSee('data-testid="cart-empty"', false);

        Livewire::actingAs($this->w['customer'])->test(Bag::class, ['embedded' => true])
            ->assertSee('Steel Bottle')
            ->assertSee('Checkout Lakshmi Stores →')
            ->assertSee('Add ₹300.00 more for free delivery (online payment).')
            ->assertDontSee('<h1', false);
    }

    public function test_a_shop_only_cart_has_no_services_row_and_an_empty_cart_says_so_once(): void
    {
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 1);
        Livewire::actingAs($this->w['customer'])->test(CartIndex::class)
            ->assertDontSee('data-testid="cart-services"', false)->assertSee('data-testid="cart-shop"', false);

        $other = $this->makeCustomer();
        Livewire::actingAs($other)->test(CartIndex::class)
            ->assertSee('data-testid="cart-empty"', false)->assertSee('Your cart is empty.')->assertDontSee('data-testid="cart-shop"', false);
    }

    public function test_the_header_count_adds_services_and_shop_items(): void
    {
        app(ServiceCartService::class)->add($this->w['customer'], $this->w['service']);
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 3);

        Livewire::actingAs($this->w['customer'])->test(CartCount::class)->assertSet('count', 4);
    }

    public function test_the_old_bag_link_moves_to_the_cart_for_good_with_the_query_kept(): void
    {
        $this->actingAs($this->w['customer'])->get('/shop/bag?utm_source=sms')
            ->assertStatus(301)->assertRedirect(route('customer.cart').'?utm_source=sms');
    }

    public function test_the_cart_reads_in_telugu_when_chosen(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        app(CartService::class)->add($this->w['customer'], $this->w['product'], 1);

        $this->actingAs($this->w['customer'])->withCookie(SiteText::COOKIE, 'te')->get(route('customer.cart'))
            ->assertOk()->assertSee('మీ కార్ట్')->assertSee('షాప్ వస్తువులు')->assertSee('Lakshmi Stores చెక్అవుట్ →');
    }
}
