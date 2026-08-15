<?php

namespace Tests\Feature\Catalog;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Services\Catalog\ServiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Master Catalog Import capability (mission Phase 14 input). Real historical
 * `1.8.0 services IMP.xlsx` shape: id, name, description, vendor_id,
 * category_id, subcategory_id, price, discount_price, duration, is_active,
 * created_at, updated_at, formatted_date, photo — all 113 real rows use
 * `duration` = 'fixed'|'starts from' and are vendor_id=1 (dropped on
 * import — services aren't vendor-owned in this schema).
 */
class ServiceImporterTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function rows(array $rows): Collection
    {
        return collect($rows)->map(fn ($r) => collect($r));
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::create(['name' => 'Appliance', 'slug' => 'appliance', 'module' => 'service', 'is_active' => true, 'external_id' => '1']);
    }

    private function subcategory(ServiceCategory $category): ServiceSubcategory
    {
        return ServiceSubcategory::create(['category_id' => $category->id, 'name' => 'Split AC', 'slug' => 'split-ac', 'is_active' => true, 'external_id' => '1']);
    }

    public function test_glover_column_aliases_price_duration_photo_are_accepted(): void
    {
        $category = $this->category();
        $subcategory = $this->subcategory($category);

        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Split AC Service', 'description' => '<p>desc</p>', 'vendor_id' => 1,
            'category_id' => 1, 'subcategory_id' => 1, 'price' => 799, 'discount_price' => 699,
            'duration' => 'fixed', 'is_active' => 1, 'created_at' => null, 'updated_at' => null,
            'formatted_date' => null, 'photo' => 'https://i.ibb.co/x.jpg',
        ]]));

        $this->assertEmpty($result['errors']);
        $attrs = $result['previewRows'][0]['attributes'];
        $this->assertSame(799.0, $attrs['base_price']);
        $this->assertSame(699.0, $attrs['discount_price']);
        $this->assertSame('fixed', $attrs['price_type']);
        $this->assertSame('https://i.ibb.co/x.jpg', $attrs['cover_image']);
        $this->assertSame($category->id, $attrs['category_id']);
        $this->assertSame($subcategory->id, $attrs['subcategory_id']);
    }

    public function test_duration_starts_from_maps_to_quote_on_inspection(): void
    {
        $this->category();
        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Split AC Repair', 'category_id' => 1, 'price' => 799, 'duration' => 'starts from', 'is_active' => 1,
        ]]));

        $this->assertEmpty($result['errors']);
        $this->assertSame('quote_on_inspection', $result['previewRows'][0]['attributes']['price_type']);
    }

    public function test_subcategory_must_belong_to_the_given_category(): void
    {
        $categoryA = ServiceCategory::create(['name' => 'A', 'slug' => 'a', 'module' => 'service', 'is_active' => true, 'external_id' => '1']);
        $categoryB = ServiceCategory::create(['name' => 'B', 'slug' => 'b', 'module' => 'service', 'is_active' => true, 'external_id' => '2']);
        ServiceSubcategory::create(['category_id' => $categoryB->id, 'name' => 'Sub of B', 'slug' => 'sub-of-b', 'is_active' => true, 'external_id' => '1']);

        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Mismatched', 'category_id' => 1, 'subcategory_id' => 1, 'price' => 500, 'is_active' => 1,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('subcategory_id', $result['errors'][0]['field']);
        $this->assertStringContainsString('does not belong to', $result['errors'][0]['message']);
    }

    public function test_discount_price_must_be_less_than_base_price(): void
    {
        $this->category();
        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Bad Discount', 'category_id' => 1, 'price' => 500, 'discount_price' => 500, 'is_active' => 1,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('discount_price', $result['errors'][0]['field']);
    }

    public function test_negative_price_is_rejected(): void
    {
        $this->category();
        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Negative', 'category_id' => 1, 'price' => -50, 'is_active' => 1,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('base_price', $result['errors'][0]['field']);
    }

    public function test_non_numeric_price_is_rejected(): void
    {
        $this->category();
        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Not A Number', 'category_id' => 1, 'price' => 'free', 'is_active' => 1,
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('base_price', $result['errors'][0]['field']);
    }

    public function test_invalid_cover_image_reference_is_rejected(): void
    {
        $this->category();
        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Bad Photo', 'category_id' => 1, 'price' => 500, 'is_active' => 1, 'photo' => 'data:text/html,<script>',
        ]]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('cover_image', $result['errors'][0]['field']);
    }

    public function test_repeat_import_is_idempotent(): void
    {
        $this->category();
        $importer = new ServiceImporter;
        $rows = $this->rows([['id' => 1, 'name' => 'Split AC Service', 'category_id' => 1, 'price' => 799, 'discount_price' => 699, 'duration' => 'fixed', 'is_active' => 1]]);

        $importer->commit($importer->validateRows($rows)['previewRows'], null, 'a.csv', false);
        $this->assertSame(1, Service::count());

        $second = $importer->validateRows($rows);
        $this->assertSame('unchanged', $second['previewRows'][0]['outcome']);
        $importer->commit($second['previewRows'], null, 'a.csv', false);
        $this->assertSame(1, Service::count());
    }

    public function test_vendor_id_and_glover_timestamp_columns_are_discarded(): void
    {
        $this->category();
        $result = (new ServiceImporter)->validateRows($this->rows([[
            'id' => 1, 'name' => 'Split AC Service', 'vendor_id' => 1, 'category_id' => 1, 'price' => 799,
            'is_active' => 1, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01', 'formatted_date' => 'Jan 2020',
        ]]));

        $this->assertEmpty($result['errors']);
        $this->assertArrayNotHasKey('vendor_id', $result['previewRows'][0]['attributes']);
        $this->assertArrayNotHasKey('created_at', $result['previewRows'][0]['attributes']);
        $this->assertArrayNotHasKey('formatted_date', $result['previewRows'][0]['attributes']);
    }
}
