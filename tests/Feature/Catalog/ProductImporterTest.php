<?php

namespace Tests\Feature\Catalog;

use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Export Everywhere + Import Where It's Safe session, Part 2 — Products
 * joins the same CatalogImporter engine Categories/Subcategories/Services
 * already use (validate -> preview -> commit, external_id matching), with
 * one real difference: Products are franchise-scoped through their owning
 * store, so a franchise-scoped actor's import must be rejected per-row
 * for a store outside their own grant — the other three entities are
 * global catalog with nothing to check.
 */
class ProductImporterTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function rows(array $rows): Collection
    {
        return collect($rows)->map(fn ($r) => collect($r));
    }

    private function store(): Store
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        return Store::create([
            'provider_id' => $provider->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'module' => 'commerce', 'name' => 'Test Store', 'slug' => 'test-store-'.uniqid(),
            'address_line' => 'Addr', 'lat' => 1.0, 'lng' => 1.0,
        ]);
    }

    public function test_valid_row_creates_a_product(): void
    {
        $store = $this->store();

        $result = (new ProductImporter)->validateRows($this->rows([[
            'name' => 'Widget', 'store_id' => $store->id, 'price' => 199.50, 'stock' => 10, 'is_active' => 1,
        ]]));

        $this->assertEmpty($result['errors']);
        $this->assertSame('created', $result['previewRows'][0]['outcome']);

        $run = (new ProductImporter)->commit($result['previewRows'], null, 'products.csv', false);

        $this->assertSame(1, $run->created_count);
        $product = Product::first();
        $this->assertSame('Widget', $product->name);
        $this->assertSame($store->id, $product->store_id);
        $this->assertEqualsWithDelta(199.50, $product->price, 0.001);
    }

    public function test_missing_name_is_rejected_with_a_clear_reason(): void
    {
        $store = $this->store();

        $result = (new ProductImporter)->validateRows($this->rows([[
            'name' => '', 'store_id' => $store->id, 'price' => 100,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('name', $result['errors'][0]['field']);
        $this->assertSame(0, Product::count());
    }

    public function test_nonexistent_store_is_rejected(): void
    {
        $result = (new ProductImporter)->validateRows($this->rows([[
            'name' => 'Orphan', 'store_id' => 999999, 'price' => 100,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('store_id', $result['errors'][0]['field']);
        $this->assertStringContainsString('does not exist', $result['errors'][0]['message']);
    }

    public function test_negative_price_is_rejected(): void
    {
        $store = $this->store();

        $result = (new ProductImporter)->validateRows($this->rows([[
            'name' => 'Bad Price', 'store_id' => $store->id, 'price' => -5,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('price', $result['errors'][0]['field']);
    }

    public function test_discount_percent_over_100_is_rejected(): void
    {
        $store = $this->store();

        $result = (new ProductImporter)->validateRows($this->rows([[
            'name' => 'Bad Discount', 'store_id' => $store->id, 'price' => 100, 'discount_percent' => 150,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('discount_percent', $result['errors'][0]['field']);
    }

    /**
     * Partial success: a mixed file with one good row and one row that has
     * a hard validation error (missing name) — the bad row is reported as
     * an error (never silently dropped), the good row still reaches
     * preview and commits (never blocked/failed by the bad row alongside
     * it). Products\Manage::validateProductsImport() is what surfaces this
     * distinction to the UI (unlike Categories/Subcategories/Services'
     * Livewire wrappers, which discard previewRows the moment ANY row
     * errors) — this test exercises the underlying ProductImporter/
     * CatalogImporter engine directly, which already behaves this way at
     * the service layer regardless of how any particular screen wires it.
     */
    public function test_partial_success_valid_rows_commit_invalid_rows_are_reported(): void
    {
        $store = $this->store();

        $result = (new ProductImporter)->validateRows($this->rows([
            ['name' => 'Good Widget', 'store_id' => $store->id, 'price' => 50],
            ['name' => '', 'store_id' => $store->id, 'price' => 50], // blank name -> hard error
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('name', $result['errors'][0]['field']);
        $this->assertCount(1, $result['previewRows'], 'The good row must still reach preview despite the other row failing.');
        $this->assertSame('Good Widget', $result['previewRows'][0]['name']);

        $run = (new ProductImporter)->commit($result['previewRows'], null, 'products.csv', false);

        $this->assertSame(1, $run->created_count);
        $this->assertSame(1, Product::count());
        $this->assertSame('Good Widget', Product::first()->name, 'Only the valid row was ever eligible to commit — the rejected row was never in previewRows at all.');
    }

    public function test_blank_row_is_skipped_not_rejected(): void
    {
        $store = $this->store();

        $result = (new ProductImporter)->validateRows($this->rows([
            ['name' => 'Real Product', 'store_id' => $store->id, 'price' => 50],
            ['name' => '', 'store_id' => '', 'price' => ''],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertSame('skipped', $result['previewRows'][1]['outcome']);
    }

    /** external_id is the matching key — a repeat import with the same external_id updates the existing row rather than creating a duplicate. */
    public function test_repeat_import_with_same_external_id_updates_not_duplicates(): void
    {
        $store = $this->store();
        $importer = new ProductImporter;

        $first = $importer->validateRows($this->rows([
            ['external_id' => 'EXT-1', 'name' => 'Widget', 'store_id' => $store->id, 'price' => 100],
        ]));
        $importer->commit($first['previewRows'], null, 'a.csv', false);
        $this->assertSame(1, Product::count());

        $second = $importer->validateRows($this->rows([
            ['external_id' => 'EXT-1', 'name' => 'Widget', 'store_id' => $store->id, 'price' => 150], // price changed
        ]));
        $this->assertSame('updated', $second['previewRows'][0]['outcome']);
        $importer->commit($second['previewRows'], null, 'a.csv', false);

        $this->assertSame(1, Product::count(), 'Same external_id must update the existing row, never create a second one.');
        $this->assertEqualsWithDelta(150.0, Product::first()->price, 0.001);
    }

    public function test_repeat_import_with_identical_data_is_unchanged(): void
    {
        $store = $this->store();
        $importer = new ProductImporter;
        $rowData = ['external_id' => 'EXT-2', 'name' => 'Stable Widget', 'store_id' => $store->id, 'price' => 75];

        $first = $importer->validateRows($this->rows([$rowData]));
        $importer->commit($first['previewRows'], null, 'a.csv', false);

        $second = $importer->validateRows($this->rows([$rowData]));
        $this->assertSame('unchanged', $second['previewRows'][0]['outcome']);
    }

    public function test_marketplace_category_resolves_when_given(): void
    {
        $store = $this->store();
        $category = MarketplaceCategory::create(['module' => 'commerce', 'name' => 'Gadgets', 'slug' => 'gadgets', 'is_active' => true]);

        $result = (new ProductImporter)->validateRows($this->rows([[
            'name' => 'Gadget X', 'store_id' => $store->id, 'marketplace_category_id' => $category->id, 'price' => 50,
        ]]));

        $this->assertEmpty($result['errors']);
        $this->assertSame($category->id, $result['previewRows'][0]['attributes']['marketplace_category_id']);
    }

    /** Scope enforcement — the prompt's central Products-specific requirement: a franchise-scoped actor cannot import into another franchise's store. */
    public function test_franchise_scoped_actor_cannot_import_into_another_franchises_store(): void
    {
        $ownStore = $this->store();
        $otherStore = $this->store(); // a completely different franchise

        $actor = $this->makeUserWithPermission('products.manage', 'franchise', $ownStore->franchise_id);

        $result = (new ProductImporter($actor))->validateRows($this->rows([
            ['name' => 'Own Store Product', 'store_id' => $ownStore->id, 'price' => 50],
            ['name' => 'Other Store Product', 'store_id' => $otherStore->id, 'price' => 50],
        ]));

        // The out-of-scope row blocks the whole preview (same "any error
        // blocks preview" contract as the missing-name case above) --
        // confirmed via the message identifying the RIGHT row/reason.
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('store_id', $result['errors'][0]['field']);
        $this->assertStringContainsString('permission', $result['errors'][0]['message']);

        // Re-upload with only their own store's row: succeeds.
        $ownOnly = (new ProductImporter($actor))->validateRows($this->rows([
            ['name' => 'Own Store Product', 'store_id' => $ownStore->id, 'price' => 50],
        ]));
        $this->assertEmpty($ownOnly['errors']);
    }

    public function test_global_actor_can_import_into_any_store(): void
    {
        $store = $this->store();
        $actor = $this->makeUserWithPermission('products.manage', 'global');

        $result = (new ProductImporter($actor))->validateRows($this->rows([
            ['name' => 'Global Import', 'store_id' => $store->id, 'price' => 50],
        ]));

        $this->assertEmpty($result['errors']);
    }

    public function test_updating_the_image_is_detected_as_a_real_change_not_masked_by_array_to_string_comparison(): void
    {
        $store = $this->store();
        $importer = new ProductImporter;

        $first = $importer->validateRows($this->rows([
            ['external_id' => 'EXT-IMG', 'name' => 'Pictured', 'store_id' => $store->id, 'price' => 50, 'image' => 'https://example.test/a.jpg'],
        ]));
        $importer->commit($first['previewRows'], null, 'a.csv', false);

        $second = $importer->validateRows($this->rows([
            ['external_id' => 'EXT-IMG', 'name' => 'Pictured', 'store_id' => $store->id, 'price' => 50, 'image' => 'https://example.test/b.jpg'],
        ]));

        $this->assertSame('updated', $second['previewRows'][0]['outcome']);
    }
}
