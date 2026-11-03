<?php

namespace Tests\Feature\Food;

use App\Livewire\Customer\Food\Home as FoodHome;
use App\Livewire\Seller\Dashboard;
use App\Livewire\Seller\Products;
use App\Livewire\Seller\Shop;
use App\Models\MarketplaceReturn;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Restaurant portal 2026-11-04 (owner's look-through of every seller screen): a restaurant's pages say "restaurant",
 * not "shop"; a restaurant has a cover photo that customers see on the Food page and the menu; no opening hours at all
 * reads "No set hours" (not "Closed" on every day); Home shows waiting problems and dishes sold out today.
 */
class RestaurantPortalTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private Store $restaurant;

    private User $owner;

    private $franchise;

    private $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        [, , $this->franchise, $this->zone] = $this->makeFranchiseTree();
        $provider = $this->makeStoreOwner($this->franchise, $this->zone);
        $this->owner = $provider->user;
        $this->restaurant = $this->makeStore($this->franchise, $this->zone, $provider, ['module' => Modules::FOOD, 'name' => 'Annapurna Mess']);
    }

    public function test_the_restaurant_page_says_restaurant_and_takes_a_cover_photo(): void
    {
        $page = Livewire::actingAs($this->owner)->test(Shop::class)
            ->assertSee('Restaurant mobile number')->assertSee('About your restaurant')->assertSee('Restaurant address')
            ->assertSee('Save restaurant details')->assertSee('Cover photo')
            ->assertDontSee('Shop mobile number')->assertDontSee('Save shop details')
            ->assertSee('No set hours: customers can order whenever you switch your restaurant Open on Home.');

        $page->set('cover', UploadedFile::fake()->image('dosa.jpg', 1200, 600))->call('save')->assertHasNoErrors();
        $store = $this->restaurant->fresh();
        $this->assertNotNull($store->cover_image);
        Storage::disk('public')->assertExists($store->cover_image);
        $this->assertNotNull($store->cover_url);
    }

    public function test_a_shop_keeps_its_shop_words(): void
    {
        $shopOwner = $this->makeStoreOwner($this->franchise, $this->zone);
        $this->makeStore($this->franchise, $this->zone, $shopOwner, ['name' => 'Bottle Shop']);

        Livewire::actingAs($shopOwner->user)->test(Shop::class)
            ->assertSee('Shop mobile number')->assertSee('Save shop details')->assertDontSee('No set hours');
    }

    public function test_home_shows_waiting_problems_and_dishes_sold_out_today_and_the_menu_search_says_menu(): void
    {
        $dish = Product::where('store_id', $this->restaurant->id)->first()
            ?? $this->makeProduct($this->restaurant, ['name' => 'Veg Meals']);
        $dish->update(['is_active' => true, 'out_of_stock_until' => now()->endOfDay()]);
        $order = $this->makeMarketplaceOrderScenario('completed')['order'];
        MarketplaceReturn::create([
            'marketplace_order_id' => $order->id, 'franchise_id' => $this->franchise->id, 'store_id' => $this->restaurant->id,
            'customer_id' => $order->customer_id, 'reason' => 'missing', 'description' => 'Missing', 'items' => [], 'photos' => [],
            'status' => MarketplaceReturn::REQUESTED, 'refund_amount' => 50,
        ]);

        Livewire::actingAs($this->owner)->test(Dashboard::class)
            ->assertSee('1 sold out today')->assertSee('1 problem waiting for you — Agree or Dispute')->assertSee('Dishes');
        Livewire::actingAs($this->owner)->test(Products::class)->assertSee('Search your menu');
    }

    public function test_the_food_page_card_shows_the_cover_photo(): void
    {
        $this->restaurant->update(['cover_image' => 'https://example.test/cover.jpg', 'fssai_number' => '12345678901234', 'is_open' => true]);
        $this->activateMarketplaceModuleFor($this->franchise, Modules::FOOD);

        $this->assertSame('https://example.test/cover.jpg', $this->restaurant->fresh()->cover_url);
        $this->get('/'.$this->franchise->city->slug.'/food/'.$this->restaurant->slug)->assertOk()
            ->assertSee('data-testid="restaurant-cover"', false)->assertSee('https://example.test/cover.jpg', false);
    }
}
