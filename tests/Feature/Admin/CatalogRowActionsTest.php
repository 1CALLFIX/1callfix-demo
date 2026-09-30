<?php

namespace Tests\Feature\Admin;

use App\Livewire\Accommodations\Manage as AccommodationsManage;
use App\Livewire\AddOns\Manage as AddOnsManage;
use App\Livewire\Badges\Manage as BadgesManage;
use App\Livewire\Equipment\Manage as EquipmentManage;
use App\Livewire\MarketplaceCategories\Manage as MarketplaceCategoriesManage;
use App\Livewire\PerformanceCampaigns\Manage as PerformanceCampaignsManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Products\Manage as ProductsManage;
use App\Livewire\Properties\Manage as PropertiesManage;
use App\Livewire\Stores\Manage as StoresManage;
use App\Livewire\Vehicles\Manage as VehiclesManage;
use App\Models\ActivityLog;
use App\Models\MarketplaceCategory;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-ADMIN-ROWACTIONS-001 — catalogue / vertical screens: Active|Inactive|Archived tabs
 * and reversible Delete, with guards that keep in-use records from being archived.
 */
class CatalogRowActionsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** @return array<string, array{0: class-string}> */
    public static function screens(): array
    {
        return [
            'properties' => [PropertiesManage::class],
            'vehicles' => [VehiclesManage::class],
            'equipment' => [EquipmentManage::class],
            'accommodations' => [AccommodationsManage::class],
            'stores' => [StoresManage::class],
            'products' => [ProductsManage::class],
            'plans' => [PlansManage::class],
            'add-ons' => [AddOnsManage::class],
            'badges' => [BadgesManage::class],
            'marketplace categories' => [MarketplaceCategoriesManage::class],
            'performance campaigns' => [PerformanceCampaignsManage::class],
        ];
    }

    #[DataProvider('screens')]
    public function test_every_screen_renders_with_an_archived_tab_and_defaults_to_all(string $component): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test($component)
            ->assertSet('activeFilter', '')
            ->assertSeeHtml('role="tab"')
            ->assertSee('Archived');
    }

    public function test_marketplace_category_archive_restore_and_the_in_use_guard(): void
    {
        $free = MarketplaceCategory::create(['module' => 'food', 'name' => 'Free Cat', 'is_active' => true]);
        $parent = MarketplaceCategory::create(['module' => 'food', 'name' => 'Parent Cat', 'is_active' => true]);
        MarketplaceCategory::create(['module' => 'food', 'name' => 'Child Cat', 'is_active' => true, 'parent_id' => $parent->id]);
        $admin = $this->makeSuperAdmin();

        $c = Livewire::actingAs($admin)->test(MarketplaceCategoriesManage::class)->set('module', 'food');
        $c->call('askArchive', $free->id)->call('confirmArchive');
        $this->assertSoftDeleted('marketplace_categories', ['id' => $free->id]);

        $c->set('activeFilter', 'archived');
        $this->assertContains($free->id, $c->viewData('categories')->pluck('id')->all());
        $c->call('restoreRow', $free->id);
        $this->assertNotSoftDeleted('marketplace_categories', ['id' => $free->id]);

        // A category that still has a sub-category cannot be archived.
        Livewire::actingAs($admin)->test(MarketplaceCategoriesManage::class)->call('askArchive', $parent->id)->assertForbidden();
        $this->assertNotSoftDeleted('marketplace_categories', ['id' => $parent->id]);
        $this->assertTrue(ActivityLog::where('subject_id', $free->id)->where('description', 'archived')->exists());
    }

    public function test_an_unused_plan_can_be_archived_and_restored(): void
    {
        $unused = Plan::create([
            'name' => 'Unused Plan', 'slug' => 'unused-plan', 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 100, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(PlansManage::class);

        $c->call('askArchive', $unused->id)->call('confirmArchive');
        $this->assertSoftDeleted('plans', ['id' => $unused->id]);

        $c->set('activeFilter', 'archived');
        $this->assertContains($unused->id, $c->viewData('plans')->pluck('id')->all());
        $c->call('restoreRow', $unused->id);
        $this->assertNotSoftDeleted('plans', ['id' => $unused->id]);
    }

    public function test_without_manage_permission_archive_is_refused(): void
    {
        $viewer = $this->makeUserWithPermission('marketplace_categories.manage', 'global');
        $cat = MarketplaceCategory::create(['module' => 'food', 'name' => 'Cat', 'is_active' => true]);
        $nobody = $this->makeUserWithNoPermissions();

        Livewire::actingAs($nobody)->test(MarketplaceCategoriesManage::class)->assertForbidden();
        // (holder of the manage permission may archive; proves the permission is what gates it)
        Livewire::actingAs($viewer)->test(MarketplaceCategoriesManage::class)->call('askArchive', $cat->id)->assertSet('confirmingArchiveId', $cat->id);
    }
}
