<?php

namespace Tests\Feature\Catalog;

use App\Models\ServiceCategory;
use App\Services\Catalog\CategoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Master Catalog Import capability (mission Phase 14 input). Direct
 * service-level coverage of CategoryImporter/CatalogImporter — the shared
 * engine behind Categories/Subcategories/Services import. Real historical
 * `1.8.12 categories IMP.xlsx` shape: id, name, is_active, image,
 * vendor_Type_id (5 = Service categories, 9 = commerce/fashion) — see
 * GLOVER_6AMMART_PARITY_AUDIT.md and CategoryImporter's own docblock.
 */
class CategoryImporterTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function rows(array $rows): Collection
    {
        return collect($rows)->map(fn ($r) => collect($r));
    }

    public function test_fresh_row_with_a_bare_id_column_is_created_with_that_id_as_external_id(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'Appliance | AC Repair', 'is_active' => 1, 'image' => 'https://i.ibb.co/x.png', 'vendor_type_id' => 5],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertSame('created', $result['previewRows'][0]['outcome']);
        $this->assertSame('1', $result['previewRows'][0]['external_id']);
        $this->assertSame('service', $result['previewRows'][0]['attributes']['module']);

        $run = (new CategoryImporter)->commit($result['previewRows'], null, 'test.csv', false);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->created_count);
        $this->assertDatabaseHas('service_categories', ['external_id' => '1', 'name' => 'Appliance | AC Repair', 'module' => 'service']);

        // The real PK was never forced to equal the source id -- it's a
        // fresh auto-increment, not necessarily 1.
        $category = ServiceCategory::where('external_id', '1')->first();
        $this->assertNotNull($category->id);
    }

    public function test_vendor_type_9_maps_to_commerce_module(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 35, 'name' => 'Sarees', 'is_active' => 1, 'image' => 'https://i.ibb.co/x.jpg', 'vendor_type_id' => 9],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertSame('commerce', $result['previewRows'][0]['attributes']['module']);
    }

    public function test_unrecognized_vendor_type_id_is_a_validation_error_not_a_silent_guess(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 99, 'name' => 'Mystery Module', 'is_active' => 1, 'vendor_type_id' => 3],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('vendor_type_id', $result['errors'][0]['field']);
    }

    public function test_repeat_import_of_the_identical_file_is_idempotent(): void
    {
        $rows = $this->rows([
            ['id' => 1, 'name' => 'Appliance | AC Repair', 'is_active' => 1, 'vendor_type_id' => 5],
        ]);

        $importer = new CategoryImporter;
        $first = $importer->validateRows($rows);
        $importer->commit($first['previewRows'], null, 'a.csv', false);

        $this->assertSame(1, ServiceCategory::count());

        // Re-import the SAME file a second time.
        $second = $importer->validateRows($rows);
        $this->assertSame('unchanged', $second['previewRows'][0]['outcome']);
        $run2 = $importer->commit($second['previewRows'], null, 'a.csv', false);

        $this->assertSame(0, $run2->created_count);
        $this->assertSame(1, $run2->unchanged_count);
        $this->assertSame(1, ServiceCategory::count(), 'Re-importing the identical file must not create a duplicate row.');
    }

    public function test_a_changed_value_on_re_import_is_reported_and_applied_as_an_update(): void
    {
        $importer = new CategoryImporter;
        $first = $importer->validateRows($this->rows([['id' => 1, 'name' => 'AC Repair', 'is_active' => 1, 'vendor_type_id' => 5]]));
        $importer->commit($first['previewRows'], null, 'a.csv', false);

        $second = $importer->validateRows($this->rows([['id' => 1, 'name' => 'AC Repair — Updated', 'is_active' => 1, 'vendor_type_id' => 5]]));
        $this->assertSame('updated', $second['previewRows'][0]['outcome']);
        $run = $importer->commit($second['previewRows'], null, 'a.csv', false);

        $this->assertSame(1, $run->updated_count);
        $this->assertSame(1, ServiceCategory::count());
        $this->assertDatabaseHas('service_categories', ['external_id' => '1', 'name' => 'AC Repair — Updated']);
    }

    public function test_duplicate_external_id_within_the_same_file_is_rejected(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'AC Repair', 'is_active' => 1, 'vendor_type_id' => 5],
            ['id' => 1, 'name' => 'AC Repair Again', 'is_active' => 1, 'vendor_type_id' => 5],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Duplicate external_id', $result['errors'][0]['message']);
    }

    public function test_invalid_image_reference_is_rejected(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'AC Repair', 'is_active' => 1, 'image' => 'javascript:alert(1)', 'vendor_type_id' => 5],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('image', $result['errors'][0]['field']);
    }

    public function test_missing_name_is_a_validation_error(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => '', 'is_active' => 1, 'vendor_type_id' => 5],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('name', $result['errors'][0]['field']);
    }

    public function test_inactive_row_imports_as_inactive(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'Retired Category', 'is_active' => 0, 'vendor_type_id' => 5],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertFalse($result['previewRows'][0]['attributes']['is_active']);

        (new CategoryImporter)->commit($result['previewRows'], null, 'a.csv', false);
        $this->assertDatabaseHas('service_categories', ['external_id' => '1', 'is_active' => 0]);
    }

    public function test_blank_row_is_skipped_not_an_error(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => null, 'name' => null, 'is_active' => null, 'vendor_type_id' => null],
            ['id' => 1, 'name' => 'AC Repair', 'is_active' => 1, 'vendor_type_id' => 5],
        ]));

        $this->assertEmpty($result['errors']);
        $this->assertSame('skipped', $result['previewRows'][0]['outcome']);
        $this->assertSame('created', $result['previewRows'][1]['outcome']);
    }

    public function test_deactivate_missing_only_touches_externally_sourced_rows(): void
    {
        $importer = new CategoryImporter;

        // A manually-created category (no external_id) plus two imported ones.
        $manual = ServiceCategory::create(['name' => 'Manually Added', 'slug' => 'manually-added', 'module' => 'service', 'is_active' => true]);
        $first = $importer->validateRows($this->rows([
            ['id' => 1, 'name' => 'Keep Me', 'is_active' => 1, 'vendor_type_id' => 5],
            ['id' => 2, 'name' => 'Drop Me', 'is_active' => 1, 'vendor_type_id' => 5],
        ]));
        $importer->commit($first['previewRows'], null, 'a.csv', false);

        // Re-import with only id=1 present, deactivateMissing on.
        $second = $importer->validateRows($this->rows([
            ['id' => 1, 'name' => 'Keep Me', 'is_active' => 1, 'vendor_type_id' => 5],
        ]));
        $run = $importer->commit($second['previewRows'], null, 'a.csv', true);

        $this->assertSame(1, $run->deactivated_count);
        $this->assertDatabaseHas('service_categories', ['external_id' => '2', 'is_active' => 0]);
        // A manually-created row (no external_id) must never be auto-deactivated.
        $this->assertDatabaseHas('service_categories', ['id' => $manual->id, 'is_active' => 1]);
    }

    public function test_commit_rolls_back_entirely_on_a_forced_failure(): void
    {
        $result = (new CategoryImporter)->validateRows($this->rows([
            ['id' => 1, 'name' => 'First', 'is_active' => 1, 'vendor_type_id' => 5],
            ['id' => 2, 'name' => 'Second', 'is_active' => 1, 'vendor_type_id' => 5],
        ]));

        // Force a genuine DB-level failure partway through: pre-seed a
        // category whose external_id collides with the unique constraint,
        // simulating a race the pre-commit check couldn't have caught.
        ServiceCategory::create(['name' => 'Racer', 'slug' => 'racer', 'module' => 'service', 'is_active' => true, 'external_id' => '2']);

        $run = (new CategoryImporter)->commit($result['previewRows'], null, 'a.csv', false);

        $this->assertSame('failed', $run->status);
        // Only the pre-seeded "Racer" row exists -- row 1 ("First") was
        // NOT left behind despite being processed before the failure.
        $this->assertSame(1, ServiceCategory::count());
        $this->assertDatabaseMissing('service_categories', ['name' => 'First']);
    }
}
