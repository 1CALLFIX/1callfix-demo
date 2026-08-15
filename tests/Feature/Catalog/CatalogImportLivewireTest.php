<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Categories\Manage as CategoriesManage;
use App\Livewire\Services\Manage as ServicesManage;
use App\Livewire\Subcategories\Manage as SubcategoriesManage;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Master Catalog Import capability (mission Phase 14 input) — end-to-end
 * coverage of the real upload -> validate -> preview -> commit path through
 * an actual uploaded CSV (CategoryImporterTest/SubcategoryImporterTest/
 * ServiceImporterTest cover the importer engine itself in isolation; this
 * proves the Livewire wiring — Excel::import(), the shared x-import-panel,
 * permission checks — actually works end to end).
 */
class CatalogImportLivewireTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($row) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row)), $rows);

        return UploadedFile::fake()->createWithContent('import.csv', implode("\n", $lines));
    }

    public function test_categories_screen_imports_a_real_csv_end_to_end(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'global');
        $file = $this->csv([
            ['id', 'name', 'is_active', 'image', 'vendor_type_id'],
            ['1', 'Appliance | AC Repair', '1', 'https://i.ibb.co/x.png', '5'],
        ]);

        $component = Livewire::actingAs($actor)->test(CategoriesManage::class)
            ->set('categoriesImportFile', $file)
            ->call('validateCategoriesImport')
            ->assertOk();

        $this->assertEmpty($component->get('categoriesImportErrors'));
        $this->assertNotEmpty($component->get('categoriesImportRows'));

        $component->call('commitCategoriesImport')->assertOk();

        $this->assertDatabaseHas('service_categories', ['external_id' => '1', 'name' => 'Appliance | AC Repair', 'module' => 'service']);
        $this->assertNotNull($component->get('categoriesImportRun'));
        $this->assertSame(1, $component->get('categoriesImportRun')->created_count);
    }

    public function test_subcategories_screen_imports_a_real_csv_end_to_end(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'global');
        ServiceCategory::create(['name' => 'Appliance', 'slug' => 'appliance', 'module' => 'service', 'is_active' => true, 'external_id' => '1']);

        $file = $this->csv([
            ['id', 'name', 'is_active', 'image', 'category_id'],
            ['1', 'Split AC', '1', '', '1'],
        ]);

        $component = Livewire::actingAs($actor)->test(SubcategoriesManage::class)
            ->set('importFile', $file)
            ->call('validateSubcategoriesImport')
            ->assertOk();

        $this->assertEmpty($component->get('importErrors'));
        $component->call('commitSubcategoriesImport')->assertOk();

        $this->assertDatabaseHas('service_subcategories', ['external_id' => '1', 'name' => 'Split AC']);
    }

    public function test_services_screen_imports_a_real_csv_end_to_end(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $category = ServiceCategory::create(['name' => 'Appliance', 'slug' => 'appliance', 'module' => 'service', 'is_active' => true, 'external_id' => '1']);
        ServiceSubcategory::create(['category_id' => $category->id, 'name' => 'Split AC', 'slug' => 'split-ac', 'is_active' => true, 'external_id' => '1']);

        $file = $this->csv([
            ['id', 'name', 'description', 'vendor_id', 'category_id', 'subcategory_id', 'price', 'discount_price', 'duration', 'is_active', 'photo'],
            ['1', 'Split AC Service', 'A real service', '1', '1', '1', '799', '699', 'fixed', '1', 'https://i.ibb.co/x.jpg'],
        ]);

        $component = Livewire::actingAs($actor)->test(ServicesManage::class)
            ->set('importFile', $file)
            ->call('validateServicesImport')
            ->assertOk();

        $this->assertEmpty($component->get('importErrors'));
        $component->call('commitServicesImport')->assertOk();

        $this->assertDatabaseHas('services', ['external_id' => '1', 'name' => 'Split AC Service', 'base_price' => 799, 'discount_price' => 699, 'price_type' => 'fixed']);
    }

    public function test_export_then_reimport_round_trips_via_external_id(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'global');
        ServiceCategory::create(['name' => 'Original', 'slug' => 'original', 'module' => 'service', 'is_active' => true, 'external_id' => '1']);

        // Re-importing an exported-shape file (external_id column present,
        // id column holds our real internal id) must UPDATE, not duplicate.
        $existing = ServiceCategory::where('external_id', '1')->sole();
        $file = $this->csv([
            ['id', 'external_id', 'name', 'module', 'is_active', 'image', 'icon', 'color', 'description', 'sort_order'],
            [(string) $existing->id, '1', 'Renamed', 'service', '1', '', '', '', '', '0'],
        ]);

        $component = Livewire::actingAs($actor)->test(CategoriesManage::class)
            ->set('categoriesImportFile', $file)
            ->call('validateCategoriesImport');

        $this->assertEmpty($component->get('categoriesImportErrors'));
        $this->assertSame('updated', $component->get('categoriesImportRows')[0]['outcome']);

        $component->call('commitCategoriesImport');

        $this->assertSame(1, ServiceCategory::count());
        $this->assertDatabaseHas('service_categories', ['id' => $existing->id, 'external_id' => '1', 'name' => 'Renamed']);
    }
}
