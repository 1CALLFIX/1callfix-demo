<?php

namespace Tests\Feature\Support;

use App\Models\AddOn;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceOrder;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Provider;
use App\Models\Store;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Support\Str;

/** Phase 24 (Marketplace Foundation) — the Marketplace counterpart to ParcelOrderFixtureHelpers/PropertyRentalFixtureHelpers. */
trait MarketplaceFixtureHelpers
{
    protected function enableMarketplaceModuleForTests(string $module = Modules::COMMERCE): void
    {
        Module::where('code', $module)->update(['is_implemented' => true]);
    }

    protected function activateMarketplaceModuleFor($franchise, string $module = Modules::COMMERCE): void
    {
        $this->enableMarketplaceModuleForTests($module);
        app(ModuleActivationService::class)->setActive($module, 'franchise', $franchise->id, true);
    }

    protected function makeStoreOwner($franchise, $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Store Owner', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    protected function makeStore($franchise, $zone, ?Provider $owner = null, array $overrides = []): Store
    {
        $owner ??= $this->makeStoreOwner($franchise, $zone);

        return Store::create(array_merge([
            'provider_id' => $owner->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'module' => Modules::COMMERCE, 'name' => 'Test Store '.Str::random(6),
            'address_line' => '123 Market Street', 'lat' => 1.0, 'lng' => 1.0,
            'is_active' => true, 'is_open' => true,
        ], $overrides));
    }

    protected function makeProduct(Store $store, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_id' => $store->id, 'name' => 'Test Product '.Str::random(6),
            'price' => 100, 'stock' => 50, 'is_active' => true, 'is_approved' => true,
        ], $overrides));
    }

    protected function makeProductVariant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id, 'name' => 'Variant '.Str::random(4), 'stock' => 20, 'is_active' => true,
        ], $overrides));
    }

    protected function makeAddOn(Store $store, array $overrides = []): AddOn
    {
        return AddOn::create(array_merge([
            'store_id' => $store->id, 'name' => 'Add-on '.Str::random(4), 'price' => 10, 'is_active' => true,
        ], $overrides));
    }

    protected function makeMarketplaceCategory(array $overrides = []): MarketplaceCategory
    {
        return MarketplaceCategory::create(array_merge([
            'module' => Modules::COMMERCE, 'name' => 'Category '.Str::random(6), 'is_active' => true,
        ], $overrides));
    }

    protected function makeDeliveryRiderIn($franchise, $zone, string $capabilityType = 'commerce_delivery_rider'): FieldWorker
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
        ]);

        $worker = FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => 1.0, 'current_lng' => 1.0,
        ]);

        FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => $capabilityType]);

        return $worker;
    }

    /** A complete, ready-to-checkout scenario -- franchise/zone, customer, store, product, and (optionally) a pre-existing marketplace order. */
    protected function makeMarketplaceOrderScenario(string $status = 'pending', array $overrides = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner);
        $product = $this->makeProduct($store);

        $order = MarketplaceOrder::create(array_merge([
            'code' => 'MTST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'store_id' => $store->id, 'module' => $store->module,
            'order_type' => 'delivery', 'subtotal' => 100, 'total_amount' => 100,
            'status' => $status, 'payment_status' => 'pending', 'payment_method' => 'online',
        ], $overrides));

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'owner', 'store', 'product', 'order');
    }
}
