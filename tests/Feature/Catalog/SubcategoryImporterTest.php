<?php

namespace Tests\Feature\Catalog;

use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Services\Catalog\CategoryImporter;
use App\Services\Catalog\SubcategoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Master Catalog Import capability (mission Phase 14 input). Real historical
 * `1.8.12 subcategories IMP.xlsx` shape: id, name, is_active, image,
 * category_id — category_id references the categories sheet's historical
 * id, which is exactly the two-step (external_id-then-real-id) relationship
 * resolution CatalogImporter::resolveRelationId() exists to handle.
 */
class SubcategoryImporterTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function rows(array $rows): Collection
    {
        return collect($rows)->map(fn ($r) => collect($r));
    }

    /** Fresh historical import: category_id in the sheet is the SOURCE system's id, resolved via the category's external_id. */
    public function test_category_id_resolves_via_the_related_categorys_external_id(): void
    {
        $catResult = (new CategoryImporter)->validateRows($this->rows([['id' => 1, 'name' => 'Appliance | AC Repair', 'is_active' => 1, 'vendor_type_id' => 5]]));
        (new CategoryImporter)->commit($catResult['previewRows'], null, 'cats.csv', false);
        $category = ServiceCategory::where('external_id', '1')->sole();

        $result = (new SubcategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'Split AC', 'is_active' => 1, 'category_id' => 1],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertSame($category->id, $result['previewRows'][0]['attributes']['category_id']);
    }

    /** Round-trip: re-importing our own exported file, whose category_id column already holds our REAL internal category id (no matching external_id). */
    public function test_category_id_falls_back_to_a_literal_real_id_match(): void
    {
        $category = ServiceCategory::create(['name' => 'Manually Added', 'slug' => 'manually-added', 'module' => 'service', 'is_active' => true]);

        $result = (new SubcategoryImporter)->validateRows($this->rows([
            ['name' => 'A Subcategory', 'is_active' => 1, 'category_id' => $category->id],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertSame($category->id, $result['previewRows'][0]['attributes']['category_id']);
    }

    public function test_unresolvable_category_id_is_a_validation_error(): void
    {
        $result = (new SubcategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'Split AC', 'is_active' => 1, 'category_id' => 999],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('category_id', $result['errors'][0]['field']);
        $this->assertStringContainsString('does not exist', $result['errors'][0]['message']);
    }

    public function test_repeat_import_is_idempotent(): void
    {
        ServiceCategory::create(['name' => 'C', 'slug' => 'c', 'module' => 'service', 'is_active' => true, 'external_id' => '1']);

        $importer = new SubcategoryImporter;
        $rows = $this->rows([['id' => 13, 'name' => 'Plumber', 'is_active' => 1, 'category_id' => 1]]);

        $importer->commit($importer->validateRows($rows)['previewRows'], null, 'a.csv', false);
        $this->assertSame(1, ServiceSubcategory::count());

        $second = $importer->validateRows($rows);
        $this->assertSame('unchanged', $second['previewRows'][0]['outcome']);
        $importer->commit($second['previewRows'], null, 'a.csv', false);
        $this->assertSame(1, ServiceSubcategory::count());
    }

    public function test_missing_category_id_is_a_validation_error(): void
    {
        $result = (new SubcategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'Split AC', 'is_active' => 1],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('category_id', $result['errors'][0]['field']);
    }
}
