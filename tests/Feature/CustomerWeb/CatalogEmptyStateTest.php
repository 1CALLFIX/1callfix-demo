<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\CategoryIndex;
use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Customer\Catalog\ServiceIndex;
use App\Livewire\Customer\Home;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Every discovery screen against an empty or nearly-empty catalog (Phase C).
 *
 * A brand-new franchise with nothing published is a real state this app will
 * be in on its first day, and "renders an error" or "renders a blank page" are
 * both unacceptable answers to it. Each screen has to stand up on its own and
 * say plainly that there is nothing there yet.
 */
class CatalogEmptyStateTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_every_discovery_route_renders_against_a_completely_empty_catalog(): void
    {
        foreach ([
            'customer.home',
            'customer.categories.index',
            'customer.services.index',
            'customer.offers',
            'customer.search',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_the_homepage_says_so_when_no_category_is_published(): void
    {
        Livewire::test(Home::class)
            ->assertSee('Home services, on call')
            ->assertSee('No categories published yet');
    }

    public function test_the_homepage_hides_every_data_driven_rail_when_there_is_no_data(): void
    {
        Livewire::test(Home::class)
            ->assertDontSee('New & noteworthy')
            ->assertDontSee('Most booked services')
            ->assertDontSee('Offers on now')
            // The trust section is qualitative and always applies, so it stays.
            ->assertSee('Why choose');
    }

    public function test_the_category_explorer_says_so_when_nothing_is_published(): void
    {
        Livewire::test(CategoryIndex::class)->assertSee('No categories published yet');
    }

    public function test_the_catalog_grid_says_so_when_nothing_is_published(): void
    {
        Livewire::test(ServiceIndex::class)->assertSee('No services published yet');
    }

    public function test_a_category_with_no_services_says_so_rather_than_rendering_an_empty_grid(): void
    {
        $category = $this->makeCategory(['name' => 'Empty Category']);

        Livewire::test(CategoryShow::class, ['category' => $category])
            ->assertSee('Empty Category')
            ->assertSee('No services in this category yet');
    }

    /**
     * An empty result caused by FILTERS is a different situation from an empty
     * catalogue, and has to offer a different way out — clearing the filters
     * rather than "come back later".
     */
    public function test_an_empty_filtered_result_offers_to_clear_the_filters(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Only Service']);

        Livewire::test(ServiceIndex::class)
            ->set('search', 'zzzznomatch')
            ->assertSee('Nothing matched those filters')
            ->assertSee('Clear filters')
            ->assertDontSee('No services published yet');
    }

    public function test_a_category_with_no_subcategories_renders_without_a_subcategory_rail(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Flat Service']);

        Livewire::test(CategoryShow::class, ['category' => $category])
            ->assertSee('Flat Service')
            ->assertDontSee('Subcategories');
    }

    /**
     * `services.base_price` is NOT NULL, so a service genuinely without a
     * price cannot exist in this schema. The nearest real case is a zero
     * price, which must render as a real number rather than as blank or as
     * "free" — that wording is a commercial claim nobody has made.
     */
    public function test_a_zero_priced_service_renders_its_price_as_a_number(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Zero Priced', 'base_price' => 0]);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Zero Priced')
            ->assertSee('0.00')
            ->assertDontSee('Free');
    }

    public function test_a_service_with_no_duration_hides_the_duration_line(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Untimed Service', 'duration_estimate_mins' => 0]);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Untimed Service')
            ->assertDontSee('Typically takes');
    }

    /**
     * A franchise override HIGHER than the list price is a legitimate local
     * price, not a discount, and must never render with a struck-through
     * "usual price" beside it.
     */
    public function test_a_higher_franchise_price_is_not_dressed_up_as_a_discount(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);

        \App\Models\FranchiseServicePricing::create([
            'franchise_id' => $franchise->id, 'service_id' => $service->id,
            'price_override' => 800, 'is_offered' => true,
        ]);

        app(\App\Services\Customer\CustomerLocationContext::class)->setZone($zone->id);

        Livewire::test(ServiceIndex::class)
            ->assertSee('800.00')
            ->assertDontSee('Usual price')
            ->assertDontSee('% off');
    }

    // ==================== Access ====================

    /**
     * Discovery is public, exactly as `GET /api/categories|subcategories|
     * services` already are — a customer browses before deciding to sign in.
     * This pins that deliberately, so nobody later "fixes" it by adding auth.
     */
    public function test_discovery_is_public_and_needs_no_authentication(): void
    {
        $category = $this->makeCategory(['name' => 'Public Category']);
        $service = $this->makeService($category, ['name' => 'Public Service']);

        $this->get(route('customer.categories.show', $category))->assertOk()->assertSeeText('Public Category');
        $this->get(route('customer.services.show', $service))->assertOk()->assertSeeText('Public Service');
        $this->get(route('customer.search', ['q' => 'Public']))->assertOk();
    }

    public function test_an_authenticated_customer_sees_the_same_catalog(): void
    {
        $category = $this->makeCategory(['name' => 'Shared Category']);

        $this->actingAs($this->makeCustomer())
            ->get(route('customer.categories.show', $category))
            ->assertOk()
            ->assertSeeText('Shared Category');
    }
}
