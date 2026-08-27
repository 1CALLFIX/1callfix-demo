<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\CategoryIndex;
use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Customer\Catalog\ServiceIndex;
use App\Livewire\Customer\Home;
use App\Models\FranchiseServicePricing;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Customer discovery: homepage rails, the category explorer, the catalog grid
 * and the location context that scopes them (Phase C).
 *
 * The assertions worth reading are the negative ones. It is easy to write a
 * catalog screen that shows the right things; the failure mode that actually
 * costs money is one that ALSO shows an unpublished service, another
 * vertical's category, or a price from the wrong franchise.
 */
class CatalogDiscoveryTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    // ==================== Visibility rule ====================

    public function test_the_homepage_shows_active_service_vertical_categories(): void
    {
        $category = $this->makeCategory(['name' => 'Visible Category']);
        $this->makeService($category);

        Livewire::test(Home::class)->assertSee('Visible Category');
    }

    public function test_an_inactive_category_never_appears_on_the_homepage(): void
    {
        $hidden = $this->makeCategory(['name' => 'Hidden Category', 'is_active' => false]);
        $this->makeService($hidden, ['name' => 'Hidden Service']);

        Livewire::test(Home::class)
            ->assertDontSee('Hidden Category')
            ->assertDontSee('Hidden Service');
    }

    /**
     * `service_categories.module` is shared across all seven verticals. Without
     * the module filter, a Marketplace or Hotel category would surface on the
     * home-services homepage — the exact leak ServiceCatalogController's own
     * docblock documents.
     */
    public function test_categories_from_another_vertical_never_appear(): void
    {
        $marketplace = $this->makeCategory(['module' => 'commerce', 'name' => 'Marketplace Category']);
        $this->makeService($marketplace, ['name' => 'Marketplace Service']);

        Livewire::test(Home::class)
            ->assertDontSee('Marketplace Category')
            ->assertDontSee('Marketplace Service');
    }

    public function test_a_service_whose_category_is_inactive_never_appears_even_though_the_service_is_active(): void
    {
        $category = $this->makeCategory(['is_active' => false]);
        $this->makeService($category, ['name' => 'Orphaned Service', 'is_active' => true]);

        Livewire::test(ServiceIndex::class)->assertDontSee('Orphaned Service');
    }

    public function test_an_inactive_service_never_appears_in_the_catalog(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Live Service']);
        $this->makeService($category, ['name' => 'Retired Service', 'is_active' => false]);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Live Service')
            ->assertDontSee('Retired Service');
    }

    // ==================== Category / subcategory navigation ====================

    public function test_the_category_page_lists_its_own_services_and_subcategories(): void
    {
        $category = $this->makeCategory(['name' => 'AC Repair']);
        $subcategory = $this->makeSubcategory($category, ['name' => 'Split AC']);
        $this->makeService($category, ['name' => 'Deep Clean', 'subcategory_id' => $subcategory->id]);

        $other = $this->makeCategory(['name' => 'Plumbing']);
        $this->makeService($other, ['name' => 'Tap Repair']);

        Livewire::test(CategoryShow::class, ['category' => $category])
            ->assertSee('AC Repair')
            ->assertSee('Split AC')
            ->assertSee('Deep Clean')
            ->assertDontSee('Tap Repair');
    }

    public function test_selecting_a_subcategory_filters_the_services_on_the_server(): void
    {
        $category = $this->makeCategory();
        $splits = $this->makeSubcategory($category, ['name' => 'Split AC']);
        $windows = $this->makeSubcategory($category, ['name' => 'Window AC']);

        $this->makeService($category, ['name' => 'Split Service', 'subcategory_id' => $splits->id]);
        $this->makeService($category, ['name' => 'Window Service', 'subcategory_id' => $windows->id]);

        Livewire::test(CategoryShow::class, ['category' => $category])
            ->assertSee('Split Service')
            ->assertSee('Window Service')
            ->call('selectSubcategory', $splits->id)
            ->assertSee('Split Service')
            ->assertDontSee('Window Service');
    }

    /**
     * `subcategory` is a URL-bound public property, so a crafted or stale
     * value arrives as untrusted input. It must not filter by another
     * category's subcategory, and it must not 404 a valid category either —
     * a stale bookmark should still show the category, unfiltered.
     */
    public function test_a_subcategory_belonging_to_another_category_is_ignored_rather_than_applied(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Mine']);

        $foreign = $this->makeSubcategory($this->makeCategory(), ['name' => 'Foreign Sub']);

        Livewire::withQueryParams(['sub' => $foreign->id])
            ->test(CategoryShow::class, ['category' => $category])
            ->assertSet('subcategory', null)
            ->assertSee('Mine');
    }

    public function test_the_category_explorer_counts_only_active_services(): void
    {
        $category = $this->makeCategory(['name' => 'Counted']);
        $this->makeService($category);
        $this->makeService($category);
        $this->makeService($category, ['is_active' => false]);

        Livewire::test(CategoryIndex::class)->assertSee('2 services');
    }

    // ==================== Sorting and filtering ====================

    public function test_price_sorting_uses_the_stored_price_cascade(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Cheap Service', 'base_price' => 100]);
        $this->makeService($category, ['name' => 'Pricey Service', 'base_price' => 900]);
        // discount_price is what the cascade uses when set, so this row sorts
        // by 50 rather than by its 800 base price.
        $this->makeService($category, ['name' => 'Discounted Service', 'base_price' => 800, 'discount_price' => 50]);

        $ordered = Livewire::test(ServiceIndex::class)
            ->set('sort', 'price_low')
            ->viewData('cards')
            ->pluck('name')
            ->all();

        $this->assertSame(['Discounted Service', 'Cheap Service', 'Pricey Service'], $ordered);
    }

    public function test_clearing_filters_restores_the_full_catalog(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Alpha Service']);
        $this->makeService($category, ['name' => 'Beta Service']);

        Livewire::test(ServiceIndex::class)
            ->set('search', 'Alpha')
            ->assertDontSee('Beta Service')
            ->call('clearFilters')
            ->assertSee('Alpha Service')
            ->assertSee('Beta Service');
    }

    // ==================== Location-aware pricing ====================

    /**
     * The franchise price override must come from the SESSION zone, never
     * from a query parameter or anything else the client controls — and it
     * must actually change the displayed price.
     */
    public function test_the_displayed_price_uses_the_franchise_override_derived_from_the_session_zone(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['base_price' => 500]);

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id,
            'service_id' => $service->id,
            'price_override' => 321,
            'is_offered' => true,
        ]);

        // No zone chosen: base price.
        Livewire::test(ServiceIndex::class)->assertSee('500.00')->assertDontSee('321.00');

        app(CustomerLocationContext::class)->setZone($zone->id);

        Livewire::test(ServiceIndex::class)->assertSee('321.00');
    }

    public function test_a_not_offered_franchise_pricing_row_does_not_apply_its_override(): void
    {
        // Matches the established behaviour ServiceCatalogApiTest already
        // pins for the API: is_offered=false removes the override, it never
        // removes the service.
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Still Listed', 'base_price' => 500]);

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id,
            'service_id' => $service->id,
            'price_override' => 999,
            'is_offered' => false,
        ]);

        app(CustomerLocationContext::class)->setZone($zone->id);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Still Listed')
            ->assertSee('500.00')
            ->assertDontSee('999.00');
    }

    public function test_changing_zone_refreshes_the_catalog_screens(): void
    {
        // The hand-off that was missing until browser testing found it: the
        // LocationPicker is a separate component, so without a listener the
        // catalog behind it kept rendering the previous zone's prices.
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['base_price' => 500]);

        FranchiseServicePricing::create([
            'franchise_id' => $franchise->id, 'service_id' => $service->id,
            'price_override' => 777, 'is_offered' => true,
        ]);

        $component = Livewire::test(ServiceIndex::class)->assertSee('500.00');

        app(CustomerLocationContext::class)->setZone($zone->id);

        $component->dispatch('customer-zone-changed')->assertSee('777.00');
    }

    // ==================== Most booked ====================

    public function test_most_booked_ranks_by_real_booking_count_and_excludes_cancelled(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $category = $scenario['category'];
        $popular = $scenario['service'];

        $quiet = $this->makeService($category, ['name' => 'Quiet Service']);

        // Two more completed bookings for the popular service, and a
        // cancelled one for the quiet service which must not count.
        foreach (range(1, 2) as $i) {
            \App\Models\Booking::create([
                'code' => 'MB-'.$i.'-'.uniqid(),
                'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
                'customer_id' => $scenario['customer']->id, 'service_id' => $popular->id,
                'address_id' => $scenario['address']->id, 'status' => 'completed',
                'price_quoted' => 500, 'payment_status' => 'paid', 'payment_method' => 'online',
            ]);
        }

        \App\Models\Booking::create([
            'code' => 'MB-X-'.uniqid(),
            'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
            'customer_id' => $scenario['customer']->id, 'service_id' => $quiet->id,
            'address_id' => $scenario['address']->id, 'status' => 'cancelled',
            'price_quoted' => 500, 'payment_status' => 'refunded', 'payment_method' => 'online',
        ]);

        $ranked = Livewire::test(Home::class)->viewData('mostBooked')->pluck('name')->all();

        $this->assertSame([$popular->name], $ranked, 'Only services with non-cancelled bookings may be ranked.');
    }

    /**
     * With no booking history there is nothing honest to rank, so the whole
     * section must disappear rather than fall back to an arbitrary order
     * relabelled as popularity.
     */
    public function test_most_booked_is_empty_when_nothing_has_been_booked(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category);

        Livewire::test(Home::class)
            ->assertViewHas('mostBooked', fn ($rail) => $rail->isEmpty())
            ->assertDontSee('Most booked services');
    }
}
