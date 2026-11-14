<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Customer\Catalog\ServiceShow;
use App\Livewire\Services\Manage as ServicesManage;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Service choices (owner 2026-11-14): Split AC General / Jet pump / Foam + jet pump / Chemical wash / Full service show
 * as ONE card with choice buttons. Each choice stays its own service, so its own price is what the customer pays.
 */
class ServiceChoicesTest extends TestCase
{
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** @return array{0: Service, 1: Service, 2: Service} main (General), Jet, Chemical */
    private function splitAcGroup(): array
    {
        $category = $this->makeCategory(['name' => 'Appliance']);
        $sub = $this->makeSubcategory($category, ['name' => 'Split AC']);
        $general = $this->makeService($category, ['subcategory_id' => $sub->id, 'name' => 'Split AC General Service',
            'base_price' => 900, 'choice_group_name' => 'Split AC Service', 'choice_label' => 'General service']);
        $jet = $this->makeService($category, ['subcategory_id' => $sub->id, 'name' => 'Split AC Jet Service',
            'base_price' => 1199, 'choice_of' => $general->id, 'choice_label' => 'Jet pump service']);
        $chemical = $this->makeService($category, ['subcategory_id' => $sub->id, 'name' => 'Split AC Chemical Wash',
            'base_price' => 1799, 'choice_of' => $general->id, 'choice_label' => 'Chemical wash']);

        return [$general, $jet, $chemical];
    }

    public function test_the_category_page_shows_one_card_for_the_group(): void
    {
        [$general] = $this->splitAcGroup();

        Livewire::test(CategoryShow::class, ['category' => $general->category])
            ->assertSee('Split AC Service')
            ->assertDontSee('Split AC Jet Service')
            ->assertDontSee('Split AC Chemical Wash');
    }

    public function test_a_search_on_the_category_page_still_finds_each_choice(): void
    {
        [$general] = $this->splitAcGroup();

        Livewire::test(CategoryShow::class, ['category' => $general->category])
            ->set('search', 'chemical')
            ->assertSee('Split AC Service') // the chemical wash card shows under its group name
            ->assertSee('1,799');
    }

    public function test_every_choice_page_shows_all_buttons_and_its_own_price(): void
    {
        [$general, $jet, $chemical] = $this->splitAcGroup();

        Livewire::test(ServiceShow::class, ['service' => $chemical])
            ->assertSee('Choose your service')
            ->assertSeeInOrder(['General service', 'Jet pump service', 'Chemical wash'])
            ->assertSee('1,799');

        Livewire::test(ServiceShow::class, ['service' => $general])
            ->assertSee('Split AC Service')
            ->assertSee('Chemical wash')
            ->assertSee('900');
    }

    public function test_an_inactive_choice_gets_no_button(): void
    {
        [$general, $jet, $chemical] = $this->splitAcGroup();
        $jet->update(['is_active' => false]);

        Livewire::test(ServiceShow::class, ['service' => $general])
            ->assertSee('Chemical wash')
            ->assertDontSee('Jet pump service');
    }

    public function test_a_service_without_a_group_shows_no_buttons(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Plain Plumbing']);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSee('Plain Plumbing')
            ->assertDontSee('Choose your service');
    }

    public function test_the_cart_line_is_the_chosen_service_at_its_own_price(): void
    {
        [, , $chemical] = $this->splitAcGroup();
        $customer = $this->makeUserWithPermission('services.manage', 'global'); // any signed-in user

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $chemical])->call('addToCart');

        $this->assertDatabaseHas('service_cart_items', ['service_id' => $chemical->id]);
    }

    public function test_admin_can_make_a_service_a_choice_and_cannot_chain_choices(): void
    {
        [$general, $jet] = $this->splitAcGroup();
        $foam = $this->makeService($general->category, ['subcategory_id' => $general->subcategory_id, 'name' => 'Split AC Foam Service', 'base_price' => 1599]);
        $admin = $this->makeUserWithPermission('services.manage', 'global');

        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $foam->id)
            ->set('editChoiceOf', (string) $general->id)
            ->set('editChoiceLabel', 'Foam + jet pump service')
            ->call('update')
            ->assertHasNoErrors();
        $this->assertSame($general->id, $foam->fresh()->choice_of);
        $this->assertSame('Foam + jet pump service', $foam->fresh()->choice_label);

        // Jet is already a choice, so it cannot be a main service.
        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $foam->id)
            ->set('editChoiceOf', (string) $jet->id)
            ->call('update')
            ->assertHasErrors('editChoiceOf');

        // General has choices, so it cannot become a choice itself.
        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $general->id)
            ->set('editChoiceOf', (string) $foam->id)
            ->call('update')
            ->assertHasErrors('editChoiceOf');
    }

    public function test_the_data_migration_groups_the_live_ac_services_and_adds_window_chemical_wash(): void
    {
        $category = $this->makeCategory(['name' => 'Appliance']);
        $split = $this->makeSubcategory($category, ['name' => 'Split AC']);
        $window = $this->makeSubcategory($category, ['name' => 'Window AC']);
        $slugs = ['split-ac-general-service' => 900, 'split-ac-jet-service' => 1199, 'split-ac-foam-service' => 1599,
            'split-ac-chemical-wash' => 1799, 'split-ac-full-service' => 2499];
        foreach ($slugs as $slug => $price) {
            $this->makeService($category, ['subcategory_id' => $split->id, 'name' => $slug, 'slug' => $slug, 'base_price' => $price]);
        }
        $windowService = $this->makeService($category, ['subcategory_id' => $window->id, 'name' => 'Window AC Service',
            'slug' => 'window-ac-service', 'base_price' => 1199]);

        $migration = require database_path('migrations/2026_11_16_100100_group_ac_service_choices.php');
        $migration->up();
        $migration->up(); // twice: changes nothing more

        $general = Service::where('slug', 'split-ac-general-service')->first();
        $this->assertSame('Split AC Service', $general->choice_group_name);
        $this->assertSame(['General service', 'Jet pump service', 'Foam + jet pump service', 'Chemical wash', 'Full service'],
            $general->choiceFamily()->pluck('choice_label')->all());
        $this->assertSame([900.0, 1199.0, 1599.0, 1799.0, 2499.0],
            $general->choiceFamily()->map(fn ($s) => (float) $s->base_price)->all()); // prices untouched

        $chemical = Service::where('slug', 'window-ac-chemical-wash')->first();
        $this->assertNotNull($chemical);
        $this->assertSame(1, Service::where('slug', 'window-ac-chemical-wash')->count());
        $this->assertSame($windowService->id, $chemical->choice_of);
        $this->assertSame(1599.0, (float) $chemical->base_price);
        $this->assertSame($window->id, $chemical->subcategory_id);
        $this->assertTrue($chemical->is_active);
        $this->assertSame('Window AC Service', $windowService->fresh()->displayName());
        $this->assertSame(['Foam service', 'Chemical wash'], $windowService->fresh()->choiceFamily()->pluck('choice_label')->all());
    }
}
