<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Services\Manage;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * services.manage was seeded but never checked anywhere (Slice 2 finding
 * #3). services carries no franchise column — global-only, same as
 * Categories\Manage.
 */
class ServicesAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function makeCategory(): ServiceCategory
    {
        return ServiceCategory::create([
            'module' => 'service', 'name' => 'Category',
            'slug' => 'category', 'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    public function test_user_without_permission_cannot_create_service(): void
    {
        $category = $this->makeCategory();
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('categoryId', (string) $category->id)
            ->set('name', 'Blocked Service')
            ->set('basePrice', '100')
            ->call('save')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('services', ['name' => 'Blocked Service']);
    }

    public function test_global_scoped_grant_can_create_service(): void
    {
        $category = $this->makeCategory();
        $actor = $this->makeUserWithPermission('services.manage', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('categoryId', (string) $category->id)
            ->set('name', 'Allowed Service')
            ->set('basePrice', '100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('services', ['name' => 'Allowed Service']);
    }

    public function test_toggle_active_denied_without_permission(): void
    {
        $category = $this->makeCategory();
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Existing Service', 'slug' => 'existing-service',
            'base_price' => 100, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->call('toggleActive', $service->id)
            ->assertHasErrors(['permission']);

        $this->assertTrue($service->fresh()->is_active);
    }

    public function test_delete_denied_without_permission(): void
    {
        $category = $this->makeCategory();
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Existing Service', 'slug' => 'existing-service',
            'base_price' => 100, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('confirmingDeleteId', $service->id)
            ->call('deleteService')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseHas('services', ['id' => $service->id, 'deleted_at' => null]);
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $category = $this->makeCategory();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('categoryId', (string) $category->id)
            ->set('name', 'Super Admin Service')
            ->set('basePrice', '100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('services', ['name' => 'Super Admin Service']);
    }
}
