<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Categories\Manage as CategoriesManage;
use App\Livewire\Subcategories\Manage as SubcategoriesManage;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * categories.manage was seeded but never checked anywhere (Slice 2 finding
 * #2). service_categories/service_subcategories carry no franchise column —
 * shared catalog data — so enforcement is global-only, same treatment as the
 * already-shipped geography.manage. Subcategories deliberately reuse
 * categories.manage rather than a new permission: the seeded label is
 * literally "Manage categories & subcategories".
 */
class CategoriesAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * Since Phase 11 added a mount()-level categories.manage gate to this
     * whole screen (it never had a separate .view permission), an actor
     * with zero permissions is now denied at mount() — never reaches
     * save() to trigger its own action-level check.
     */
    public function test_user_without_permission_cannot_view_or_create_category(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(CategoriesManage::class)->assertForbidden();

        $this->assertDatabaseMissing('service_categories', ['name' => 'Blocked Category']);
    }

    public function test_global_scoped_grant_can_create_category(): void
    {
        $actor = $this->makeUserWithPermission('categories.manage', 'global');

        Livewire::actingAs($actor)->test(CategoriesManage::class)
            ->set('name', 'Allowed Category')
            ->set('iconFile', UploadedFile::fake()->image('icon.png'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_categories', ['name' => 'Allowed Category']);
    }

    public function test_franchise_scoped_grant_does_not_cover_global_catalog_data(): void
    {
        // service_categories has no franchise column at all — a
        // franchise-scoped grant can never cover it, only a global one.
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('categories.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(CategoriesManage::class)
            ->set('name', 'Franchise Scoped Category')
            ->set('iconFile', UploadedFile::fake()->image('icon.png'))
            ->call('save')
            ->assertHasErrors(['permission']);
    }

    public function test_delete_is_denied_without_permission(): void
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Existing Category',
            'slug' => 'existing-category', 'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(CategoriesManage::class)->assertForbidden();

        $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
    }

    public function test_subcategory_create_uses_categories_manage_not_a_new_permission(): void
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Parent Category',
            'slug' => 'parent-category', 'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $actor = $this->makeUserWithPermission('categories.manage', 'global');

        Livewire::actingAs($actor)->test(SubcategoriesManage::class)
            ->set('categoryId', (string) $category->id)
            ->set('name', 'Allowed Subcategory')
            ->set('iconFile', UploadedFile::fake()->image('icon.png'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_subcategories', ['name' => 'Allowed Subcategory']);
    }

    public function test_subcategory_create_without_categories_manage_is_denied(): void
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Parent Category',
            'slug' => 'parent-category', 'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(SubcategoriesManage::class)->assertForbidden();

        $this->assertDatabaseMissing('service_subcategories', ['name' => 'Blocked Subcategory']);
    }

    public function test_subcategory_toggle_denied_without_permission(): void
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Parent Category',
            'slug' => 'parent-category', 'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $subcategory = ServiceSubcategory::create([
            'category_id' => $category->id, 'name' => 'Existing Sub',
            'slug' => 'existing-sub', 'image' => 'subcategories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(SubcategoriesManage::class)->assertForbidden();

        $this->assertTrue($subcategory->fresh()->is_active);
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CategoriesManage::class)
            ->set('name', 'Super Admin Category')
            ->set('iconFile', UploadedFile::fake()->image('icon.png'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_categories', ['name' => 'Super Admin Category']);
    }
}
