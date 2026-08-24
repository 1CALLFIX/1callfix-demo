<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Services\Manage as ServicesManage;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceOption;
use App\Models\ServiceOptionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Prompt 25 follow-up -- Services\Manage's Options modal (option groups +
 * priced options, added in "feat(admin): complete service options and
 * provider assignment") shipped with zero test coverage. Closes that gap:
 * group/option CRUD, the required/allow-multiple toggles, cascade-delete at
 * both the group and service level (service_option_groups.service_id and
 * service_options.service_option_group_id are both cascadeOnDelete() at the
 * DB level), and the services.manage permission boundary the action methods
 * enforce.
 *
 * That boundary is narrower than mount()'s own gate: mount() checks
 * hasPermissionAnywhere('services.manage') (any scope satisfies it), but
 * every action method (addOptionGroup, addOption, the toggle/delete methods) calls
 * hasPermission('services.manage') with NO scope argument -- per
 * AuthorizationService::scopeCovers(), only a 'global'-scoped grant can
 * ever satisfy an empty requested scope. A franchise-scoped grant (the
 * common shape for most seeded system roles) passes mount() but is
 * silently refused by every mutating action -- a real production boundary,
 * not a synthetic edge case, and worth its own test below.
 */
class ServiceOptionsManagementTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function makeService(): Service
    {
        $category = ServiceCategory::create([
            'name' => 'Appliance', 'slug' => 'appliance-'.Str::random(6), 'module' => 'service', 'is_active' => true,
        ]);

        return Service::create([
            'category_id' => $category->id, 'name' => 'AC Repair', 'slug' => 'ac-repair-'.Str::random(6),
            'base_price' => 500, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
    }

    private function makeGroup(Service $service, array $overrides = []): ServiceOptionGroup
    {
        return ServiceOptionGroup::create(array_merge([
            'service_id' => $service->id, 'name' => 'Tonnage', 'is_required' => false, 'allow_multiple' => false, 'sort_order' => 1,
        ], $overrides));
    }

    private function makeOption(ServiceOptionGroup $group, array $overrides = []): ServiceOption
    {
        return ServiceOption::create(array_merge([
            'service_option_group_id' => $group->id, 'name' => '1 Ton', 'price_delta' => 0, 'sort_order' => 1, 'is_active' => true,
        ], $overrides));
    }

    public function test_global_services_manage_actor_can_create_an_option_group(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->assertSet('showOptionsModal', true)
            ->set('newGroupName', 'AC Tonnage')
            ->set('newGroupRequired', true)
            ->call('addOptionGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_option_groups', [
            'service_id' => $service->id, 'name' => 'AC Tonnage', 'is_required' => true, 'allow_multiple' => false,
        ]);
    }

    public function test_toggling_required_and_allow_multiple_flips_the_stored_flags(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();
        $group = $this->makeGroup($service);

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->call('toggleGroupRequired', $group->id)
            ->call('toggleGroupAllowMultiple', $group->id);

        $this->assertDatabaseHas('service_option_groups', [
            'id' => $group->id, 'is_required' => true, 'allow_multiple' => true,
        ]);
    }

    public function test_actor_can_add_a_priced_option_to_a_group(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();
        $group = $this->makeGroup($service);

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->set("newOptionName.{$group->id}", '1.5 Ton Split AC')
            ->set("newOptionPriceDelta.{$group->id}", '500')
            ->call('addOption', $group->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_options', [
            'service_option_group_id' => $group->id, 'name' => '1.5 Ton Split AC', 'price_delta' => 500, 'is_active' => true,
        ]);
    }

    public function test_adding_an_option_requires_a_name_and_a_numeric_price_delta(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();
        $group = $this->makeGroup($service);

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->set("newOptionName.{$group->id}", '')
            ->set("newOptionPriceDelta.{$group->id}", 'not-a-number')
            ->call('addOption', $group->id)
            ->assertHasErrors(["newOptionName.{$group->id}", "newOptionPriceDelta.{$group->id}"]);

        $this->assertDatabaseCount('service_options', 0);
    }

    public function test_toggling_option_active_flips_the_stored_flag(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();
        $group = $this->makeGroup($service);
        $option = $this->makeOption($group);

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->call('toggleOptionActive', $option->id);

        $this->assertDatabaseHas('service_options', ['id' => $option->id, 'is_active' => false]);
    }

    public function test_deleting_an_option_removes_only_that_option(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();
        $group = $this->makeGroup($service);
        $keep = $this->makeOption($group, ['name' => 'Keep', 'sort_order' => 1]);
        $delete = $this->makeOption($group, ['name' => 'Delete', 'sort_order' => 2]);

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->call('deleteOption', $delete->id);

        $this->assertDatabaseMissing('service_options', ['id' => $delete->id]);
        $this->assertDatabaseHas('service_options', ['id' => $keep->id]);
    }

    public function test_deleting_an_option_group_cascades_to_its_options(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'global');
        $service = $this->makeService();
        $group = $this->makeGroup($service);
        $option = $this->makeOption($group);

        Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->call('deleteOptionGroup', $group->id);

        $this->assertDatabaseMissing('service_option_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('service_options', ['id' => $option->id]);
    }

    public function test_deleting_the_service_cascades_through_groups_to_options(): void
    {
        $service = $this->makeService();
        $group = $this->makeGroup($service);
        $option = $this->makeOption($group);

        // Service uses SoftDeletes -- forceDelete() actually exercises the
        // DB-level cascadeOnDelete() FK chain, not a soft-delete no-op that
        // would leave the child rows untouched.
        $service->forceDelete();

        $this->assertDatabaseMissing('service_option_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('service_options', ['id' => $option->id]);
    }

    public function test_franchise_scoped_services_manage_actor_can_open_the_modal_but_cannot_mutate_options(): void
    {
        $actor = $this->makeUserWithPermission('services.manage', 'franchise', 1);
        $service = $this->makeService();
        $group = $this->makeGroup($service);
        $option = $this->makeOption($group);

        $component = Livewire::actingAs($actor)->test(ServicesManage::class)
            ->call('openOptions', $service->id)
            ->assertSet('showOptionsModal', true);

        $component->set('newGroupName', 'Blocked Group')
            ->call('addOptionGroup')
            ->assertHasErrors(['permission']);
        $this->assertDatabaseMissing('service_option_groups', ['name' => 'Blocked Group']);

        $component->set("newOptionName.{$group->id}", 'Blocked Option')
            ->set("newOptionPriceDelta.{$group->id}", '100')
            ->call('addOption', $group->id)
            ->assertHasErrors(['permission']);
        $this->assertDatabaseMissing('service_options', ['name' => 'Blocked Option']);

        $component->call('toggleGroupRequired', $group->id)->assertHasErrors(['permission']);
        $this->assertDatabaseHas('service_option_groups', ['id' => $group->id, 'is_required' => false]);

        $component->call('toggleOptionActive', $option->id)->assertHasErrors(['permission']);
        $this->assertDatabaseHas('service_options', ['id' => $option->id, 'is_active' => true]);

        $component->call('deleteOption', $option->id)->assertHasErrors(['permission']);
        $this->assertDatabaseHas('service_options', ['id' => $option->id]);

        $component->call('deleteOptionGroup', $group->id)->assertHasErrors(['permission']);
        $this->assertDatabaseHas('service_option_groups', ['id' => $group->id]);
    }

    public function test_actor_without_services_manage_anywhere_cannot_mount_the_screen_at_all(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(ServicesManage::class)->assertForbidden();
    }
}
