<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Categories\Manage as CategoriesManage;
use App\Livewire\Services\Manage as ServicesManage;
use App\Livewire\Subcategories\Manage as SubcategoriesManage;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Master Catalog Import capability (mission Phase 14 input) — authorization
 * coverage. categories.manage/services.manage already require a GLOBAL
 * grant for every write action on these screens (no franchise/zone/country
 * column exists anywhere in this catalog schema — confirmed during
 * planning and kept that way on purpose). "Respect scope" for catalog
 * imports means exactly that existing rule applies to imports too — a
 * franchise-scoped grant of the same permission must still be rejected.
 */
class CatalogImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_commit_categories_import_denied_without_permission(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'franchise', $this->makeFranchise()->id);

        $component = Livewire::actingAs($actor)->test(CategoriesManage::class)
            ->set('categoriesImportRows', [['row' => 2, 'external_id' => '1', 'name' => 'X', 'attributes' => ['module' => 'service', 'name' => 'X', 'is_active' => true, 'image' => null, 'icon' => null, 'color' => null, 'description' => null, 'sort_order' => 0], 'outcome' => 'created', 'existing_id' => null, 'possible_duplicate' => false]])
            ->call('commitCategoriesImport');

        $this->assertSame('permission', $component->get('categoriesImportErrors')[0]['field']);
        $this->assertDatabaseMissing('service_categories', ['name' => 'X']);
    }

    public function test_commit_categories_import_allowed_with_global_permission(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'global');

        Livewire::actingAs($actor)->test(CategoriesManage::class)
            ->set('categoriesImportRows', [['row' => 2, 'external_id' => '1', 'name' => 'X', 'attributes' => ['module' => 'service', 'name' => 'X', 'is_active' => true, 'image' => null, 'icon' => null, 'color' => null, 'description' => null, 'sort_order' => 0], 'outcome' => 'created', 'existing_id' => null, 'possible_duplicate' => false]])
            ->call('commitCategoriesImport');

        $this->assertDatabaseHas('service_categories', ['name' => 'X', 'external_id' => '1']);
    }

    public function test_commit_subcategories_import_denied_with_franchise_scoped_grant(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'franchise', $this->makeFranchise()->id);
        $category = ServiceCategory::create(['name' => 'C', 'slug' => 'c', 'module' => 'service', 'is_active' => true]);

        $component = Livewire::actingAs($actor)->test(SubcategoriesManage::class)
            ->set('importRows', [['row' => 2, 'external_id' => '1', 'name' => 'X', 'attributes' => ['category_id' => $category->id, 'name' => 'X', 'is_active' => true, 'image' => null, 'icon' => null, 'description' => null, 'sort_order' => 0], 'outcome' => 'created', 'existing_id' => null, 'possible_duplicate' => false]])
            ->call('commitSubcategoriesImport');

        $this->assertSame('permission', $component->get('importErrors')[0]['field']);
        $this->assertDatabaseMissing('service_subcategories', ['name' => 'X']);
    }

    public function test_commit_services_import_denied_without_services_manage(): void
    {
        $actor = $this->makeUserWithNoPermissions();
        $this->grantPermission($actor, 'services.manage', 'franchise', $this->makeFranchise()->id);
        $category = ServiceCategory::create(['name' => 'C', 'slug' => 'c', 'module' => 'service', 'is_active' => true]);

        $component = Livewire::actingAs($actor)->test(ServicesManage::class)
            ->set('importRows', [['row' => 2, 'external_id' => '1', 'name' => 'X', 'attributes' => ['category_id' => $category->id, 'subcategory_id' => null, 'name' => 'X', 'description' => null, 'base_price' => 100.0, 'discount_price' => null, 'price_type' => 'fixed', 'duration_estimate_mins' => 60, 'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'cover_image' => null, 'sort_order' => 0], 'outcome' => 'created', 'existing_id' => null, 'possible_duplicate' => false]])
            ->call('commitServicesImport');

        $this->assertSame('permission', $component->get('importErrors')[0]['field']);
        $this->assertDatabaseMissing('services', ['name' => 'X']);
    }
}
