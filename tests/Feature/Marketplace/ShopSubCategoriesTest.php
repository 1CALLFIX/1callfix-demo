<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Customer\Shop\Home;
use App\Models\MarketplaceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** Owner 2026-11-05 ("subcategories is missed"): master-list products sit in sub-categories; the shop shows both rows. */
class ShopSubCategoriesTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    public function test_a_category_with_products_only_in_sub_categories_shows_and_opens_its_sub_category_row(): void
    {
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores']);

        $clothing = MarketplaceCategory::create(['module' => 'commerce', 'name' => 'Clothing', 'slug' => 'clothing', 'position' => 1, 'is_active' => true]);
        $tops = MarketplaceCategory::create(['module' => 'commerce', 'parent_id' => $clothing->id, 'name' => "Men's Topwear", 'slug' => 'mens-topwear', 'position' => 1, 'is_active' => true]);
        $kurtas = MarketplaceCategory::create(['module' => 'commerce', 'parent_id' => $clothing->id, 'name' => 'Kurtas', 'slug' => 'kurtas', 'position' => 2, 'is_active' => true]);
        MarketplaceCategory::create(['module' => 'commerce', 'parent_id' => $clothing->id, 'name' => 'Sarees', 'slug' => 'sarees', 'position' => 3, 'is_active' => true]);
        $this->makeProduct($store, ['name' => 'Polo T-Shirt', 'marketplace_category_id' => $tops->id]);
        $this->makeProduct($store, ['name' => 'Cotton Kurta', 'marketplace_category_id' => $kurtas->id]);

        $page = Livewire::test(Home::class, ['city' => 'nellore'])
            ->assertSee('Clothing')->assertDontSee('data-testid="shop-subcategories"', false);

        $page->call('pickCategory', 'clothing')
            ->assertSee('data-testid="shop-subcategories"', false)->assertSee('Men&#039;s Topwear', false)->assertSee('Kurtas')
            ->assertDontSee('Sarees') // no products there yet
            ->assertSee('Polo T-Shirt')->assertSee('Cotton Kurta');

        $page->call('pickSubCategory', 'kurtas')->assertSee('Cotton Kurta')->assertDontSee('Polo T-Shirt')
            ->assertSee('data-testid="shop-subcategories"', false);

        // Tap it again: back to the whole category.
        $page->call('pickSubCategory', 'kurtas')->assertSet('categorySlug', 'clothing')->assertSee('Polo T-Shirt');
    }

    public function test_drop_downs_list_each_sub_category_under_its_own_category(): void
    {
        $a = MarketplaceCategory::create(['module' => 'commerce', 'name' => 'Clothing', 'slug' => 'clothing', 'is_active' => true]);
        $b = MarketplaceCategory::create(['module' => 'commerce', 'name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $phones = MarketplaceCategory::create(['module' => 'commerce', 'parent_id' => $b->id, 'name' => 'Accessories', 'slug' => 'acc', 'is_active' => true]);
        $tops = MarketplaceCategory::create(['module' => 'commerce', 'parent_id' => $a->id, 'name' => 'Topwear', 'slug' => 'top', 'is_active' => true]);

        $sorted = MarketplaceCategory::query()->orderBy('name')->get();
        $this->assertSame(['Clothing', 'Topwear', 'Electronics', 'Accessories'], MarketplaceCategory::grouped($sorted)->pluck('name')->all());
    }
}
