<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Services\Manage as ServicesManage;
use App\Models\Service;
use App\Models\ServiceCartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * One page with sections (owner 2026-11-14, the Urban Company way): open Split AC and every service shows under
 * coloured headings ("Service", "Repair & gas", "Install & move") with a big Add button. Each item is its own real
 * service, so Add puts THAT service in the cart at its own price; nothing here changes any price.
 */
class SubcategorySectionsTest extends TestCase
{
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** @return array<string, Service> */
    private function splitAc(): array
    {
        $category = $this->makeCategory(['name' => 'Appliance']);
        $sub = $this->makeSubcategory($category, ['name' => 'Split AC']);
        $make = fn (string $name, float $price, array $extra = []) => $this->makeService($category,
            ['subcategory_id' => $sub->id, 'name' => $name, 'base_price' => $price] + $extra);

        $general = $make('Split AC General Service', 900, ['choice_group_name' => 'Split AC Service', 'choice_label' => 'General service', 'page_section' => 'Service', 'sort_order' => 1]);
        $chemical = $make('Split AC Chemical Wash', 1799, ['choice_of' => $general->id, 'choice_label' => 'Chemical wash', 'page_section' => 'Service', 'sort_order' => 2]);
        $gas = $make('Split AC Gas Top-Up', 2399, ['page_section' => 'Repair & gas', 'sort_order' => 3]);
        $install = $make('Split AC Install', 1699, ['page_section' => 'Install & move', 'sort_order' => 4]);

        return compact('general', 'chemical', 'gas', 'install');
    }

    public function test_a_subcategory_with_sections_shows_them_in_order_with_add_buttons(): void
    {
        $s = $this->splitAc();

        Livewire::test(CategoryShow::class, ['category' => $s['general']->category])
            ->call('selectSubcategory', $s['general']->subcategory_id)
            ->assertSeeHtml('data-testid="service-sections"')
            ->assertSeeInOrder(['Service', 'Split AC Service', 'General service', 'Chemical wash', 'Repair &amp; gas', 'Split AC Gas Top-Up', 'Install &amp; move', 'Split AC Install'], false)
            ->assertSee('1,799')
            ->assertSee('2,399')
            ->assertSee('Add');
    }

    public function test_all_and_searches_keep_the_normal_card_grid(): void
    {
        $s = $this->splitAc();

        Livewire::test(CategoryShow::class, ['category' => $s['general']->category])
            ->assertDontSeeHtml('data-testid="service-sections"')
            ->call('selectSubcategory', $s['general']->subcategory_id)
            ->set('search', 'gas')
            ->assertDontSeeHtml('data-testid="service-sections"')
            ->assertSee('Split AC Gas Top-Up');
    }

    public function test_a_subcategory_without_sections_keeps_the_card_grid(): void
    {
        $category = $this->makeCategory();
        $sub = $this->makeSubcategory($category, ['name' => 'Geyser']);
        $this->makeService($category, ['subcategory_id' => $sub->id, 'name' => 'Geyser Repair']);

        Livewire::test(CategoryShow::class, ['category' => $category])
            ->call('selectSubcategory', $sub->id)
            ->assertDontSeeHtml('data-testid="service-sections"')
            ->assertSee('Geyser Repair');
    }

    public function test_add_puts_that_one_service_in_the_cart_and_the_stepper_takes_it_out(): void
    {
        $s = $this->splitAc();
        $customer = $this->makeUserWithPermission('services.manage', 'global'); // any signed-in user

        $page = Livewire::actingAs($customer)->test(CategoryShow::class, ['category' => $s['general']->category])
            ->call('selectSubcategory', $s['general']->subcategory_id)
            ->call('addService', $s['chemical']->id)
            ->assertHasNoErrors()
            ->assertSee('Added to your cart.')
            ->assertSeeHtml('data-testid="section-cart-bar"')
            ->call('addService', $s['gas']->id)
            ->call('addService', $s['gas']->id);

        $this->assertSame(1, (int) ServiceCartItem::where('user_id', $customer->id)->where('service_id', $s['chemical']->id)->sum('quantity'));
        $this->assertSame(2, (int) ServiceCartItem::where('user_id', $customer->id)->where('service_id', $s['gas']->id)->sum('quantity'));
        $this->assertSame(0, ServiceCartItem::where('service_id', $s['general']->id)->count()); // the pick, not the main card

        $page->call('removeOne', $s['gas']->id)->call('removeOne', $s['gas']->id);
        $this->assertSame(0, ServiceCartItem::where('service_id', $s['gas']->id)->count());
    }

    public function test_a_service_from_another_subcategory_cannot_be_added_from_this_page(): void
    {
        $s = $this->splitAc();
        $other = $this->makeService($this->makeCategory(), ['name' => 'Sofa Cleaning']);
        $customer = $this->makeUserWithPermission('services.manage', 'global');

        Livewire::actingAs($customer)->test(CategoryShow::class, ['category' => $s['general']->category])
            ->call('selectSubcategory', $s['general']->subcategory_id)
            ->call('addService', $other->id);

        $this->assertSame(0, ServiceCartItem::count());
    }

    public function test_a_guest_who_taps_add_goes_to_sign_in_and_nothing_is_added(): void
    {
        $s = $this->splitAc();

        Livewire::test(CategoryShow::class, ['category' => $s['general']->category])
            ->call('selectSubcategory', $s['general']->subcategory_id)
            ->call('addService', $s['gas']->id)
            ->assertRedirect();

        $this->assertSame(0, ServiceCartItem::count());
    }

    public function test_a_service_with_a_required_question_opens_its_own_page_instead(): void
    {
        $s = $this->splitAc();
        $this->makeOptionGroup($s['install'], ['LG' => 0, 'Voltas' => 0], required: true, name: 'AC brand');
        $customer = $this->makeUserWithPermission('services.manage', 'global');

        Livewire::actingAs($customer)->test(CategoryShow::class, ['category' => $s['general']->category])
            ->call('selectSubcategory', $s['general']->subcategory_id)
            ->call('addService', $s['install']->id)
            ->assertRedirect(\App\Support\Seo\PublicUrl::service($s['install']));

        $this->assertSame(0, ServiceCartItem::count());
    }

    public function test_admin_sets_a_section_by_typing_or_tapping_a_suggestion(): void
    {
        $s = $this->splitAc();
        $repair = $this->makeService($s['general']->category, ['subcategory_id' => $s['general']->subcategory_id, 'name' => 'Split AC Repair']);
        $admin = $this->makeUserWithPermission('services.manage', 'global');

        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $repair->id)
            ->assertSee('Section on the page')
            ->assertSee('+ Repair & gas')
            ->call('pickPageSection', 'Repair & gas')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame('Repair & gas', $repair->fresh()->page_section);

        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $repair->id)
            ->set('editPageSection', '  ')
            ->call('update');
        $this->assertNull($repair->fresh()->page_section);
    }

    public function test_the_data_migration_puts_the_ac_services_in_sections_and_orders_them(): void
    {
        $category = $this->makeCategory(['name' => 'Appliance']);
        $split = $this->makeSubcategory($category, ['name' => 'Split AC']);
        $geyser = $this->makeSubcategory($category, ['name' => 'Geyser Repair']);
        $make = fn (string $name, int $sort, array $extra = []) => $this->makeService($category,
            ['subcategory_id' => $split->id, 'name' => $name, 'sort_order' => $sort] + $extra);

        // Live order today: gas and install were created before the service card.
        $gasFull = $make('Split AC Gas Charging Full', 11);
        $install = $make('Split AC Install', 12);
        $general = $make('Split AC General Service', 13, ['choice_group_name' => 'Split AC Service']);
        $chemical = $make('Split AC Chemical Wash', 14, ['choice_of' => $general->id]);
        $repair = $make('Split AC Repair', 15);
        $uninstall = $make('Split AC Un-Install', 16);
        $shifting = $make('Split AC Shifting', 17);
        $gasTop = $make('Split AC Gas Top- Up', 18);
        $geyserRepair = $this->makeService($category, ['subcategory_id' => $geyser->id, 'name' => 'Geyser Repair', 'sort_order' => 5]);

        $migration = require database_path('migrations/2026_11_17_100100_ac_page_sections.php');
        $migration->up();
        $migration->up(); // twice: changes nothing more

        $this->assertSame('Service', $general->fresh()->page_section);
        $this->assertSame('Service', $chemical->fresh()->page_section);
        foreach ([$gasFull, $repair, $gasTop] as $s) {
            $this->assertSame('Repair & gas', $s->fresh()->page_section, $s->name);
        }
        foreach ([$install, $uninstall, $shifting] as $s) {
            $this->assertSame('Install & move', $s->fresh()->page_section, $s->name);
        }
        $this->assertNull($geyserRepair->fresh()->page_section);
        $this->assertSame(5, $geyserRepair->fresh()->sort_order); // nothing outside AC moves

        // Same sort numbers as before, reshuffled so the sections come Service, Repair & gas, Install & move.
        $order = Service::where('subcategory_id', $split->id)->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Split AC General Service', 'Split AC Chemical Wash', 'Split AC Gas Charging Full', 'Split AC Repair',
            'Split AC Gas Top- Up', 'Split AC Install', 'Split AC Un-Install', 'Split AC Shifting'], $order);
        $this->assertSame(range(11, 18), Service::where('subcategory_id', $split->id)->orderBy('sort_order')->pluck('sort_order')->all());
    }
}
