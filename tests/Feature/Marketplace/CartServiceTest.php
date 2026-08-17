<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** Phase 24 (Marketplace Foundation). CartService is a browse-time convenience, not the concurrency-safety boundary (see class docblock) -- covered here in isolation from checkout. */
class CartServiceTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_adding_an_item_creates_a_cart_line_with_a_price_snapshot(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 150]);

        $item = app(CartService::class)->add($customer, $product, 2);

        $this->assertSame(2, $item->quantity);
        $this->assertSame(150.0, (float) $item->unit_price_snapshot);
    }

    public function test_adding_the_same_product_variant_and_add_ons_merges_into_the_existing_line(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        $addOn = $this->makeAddOn($store);

        app(CartService::class)->add($customer, $product, 1, null, [$addOn->id]);
        app(CartService::class)->add($customer, $product, 2, null, [$addOn->id]);

        $this->assertSame(1, CartItem::count());
        $this->assertSame(3, CartItem::first()->quantity);
    }

    public function test_a_different_variant_selection_creates_a_separate_line(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        $variantA = $this->makeProductVariant($product);
        $variantB = $this->makeProductVariant($product);

        app(CartService::class)->add($customer, $product, 1, $variantA);
        app(CartService::class)->add($customer, $product, 1, $variantB);

        $this->assertSame(2, CartItem::count());
    }

    public function test_updating_quantity_to_zero_removes_the_line(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        $cart = app(CartService::class);
        $item = $cart->add($customer, $product, 1);

        $cart->updateQuantity($item, 0);

        $this->assertSame(0, CartItem::count());
    }

    public function test_a_customer_can_hold_independent_carts_across_different_stores(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $storeA = $this->makeStore($franchise, $zone);
        $storeB = $this->makeStore($franchise, $zone);
        $productA = $this->makeProduct($storeA);
        $productB = $this->makeProduct($storeB);
        $cart = app(CartService::class);

        $cart->add($customer, $productA, 1);
        $cart->add($customer, $productB, 1);

        $this->assertCount(1, $cart->listForStore($customer, $storeA->id));
        $this->assertCount(1, $cart->listForStore($customer, $storeB->id));
    }

    public function test_add_ons_total_is_derived_live_from_the_add_ons_table(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100]);
        $addOn = $this->makeAddOn($store, ['price' => 25]);
        $cart = app(CartService::class);

        $item = $cart->add($customer, $product, 1, null, [$addOn->id]);

        $this->assertSame(25.0, $cart->addOnsTotalFor($item));
        $this->assertSame(125.0, $cart->subtotalForStore($customer, $store->id));
    }

    public function test_clearing_a_store_cart_leaves_other_stores_carts_intact(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $storeA = $this->makeStore($franchise, $zone);
        $storeB = $this->makeStore($franchise, $zone);
        $cart = app(CartService::class);
        $cart->add($customer, $this->makeProduct($storeA), 1);
        $cart->add($customer, $this->makeProduct($storeB), 1);

        $cart->clearForStore($customer, $storeA->id);

        $this->assertCount(0, $cart->listForStore($customer, $storeA->id));
        $this->assertCount(1, $cart->listForStore($customer, $storeB->id));
    }
}
