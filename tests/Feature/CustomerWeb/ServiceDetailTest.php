<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\ServiceShow;
use App\Models\Review;
use App\Models\ServiceOption;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The service detail screen: option configuration, the server-computed
 * estimate, ratings, and the 404 rules (Phase C).
 *
 * The estimate assertions are the important ones. Option selection arrives
 * from the browser as IDs; the price attached to each is re-read from the
 * database and summed in PHP. A test that only checked the displayed total
 * would pass just as happily against an implementation that trusted a
 * client-supplied price, so the tampering cases below are explicit.
 */
class ServiceDetailTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    // ==================== Visibility / 404 ====================

    public function test_an_active_service_renders(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Renders Fine']);

        $this->get(route('customer.services.show', $service))
            ->assertOk()
            ->assertSeeText('Renders Fine');
    }

    public function test_an_inactive_service_is_a_404(): void
    {
        $service = $this->makeService($this->makeCategory(), ['is_active' => false]);

        $this->get(route('customer.services.show', $service))->assertNotFound();
    }

    public function test_a_service_in_an_inactive_category_is_a_404(): void
    {
        $service = $this->makeService($this->makeCategory(['is_active' => false]));

        $this->get(route('customer.services.show', $service))->assertNotFound();
    }

    public function test_a_service_from_another_vertical_is_a_404(): void
    {
        $service = $this->makeService($this->makeCategory(['module' => 'commerce']));

        $this->get(route('customer.services.show', $service))->assertNotFound();
    }

    public function test_an_unknown_service_id_is_a_404(): void
    {
        $this->get('/services/999999')->assertNotFound();
    }

    public function test_an_inactive_category_page_is_a_404(): void
    {
        $category = $this->makeCategory(['is_active' => false]);

        $this->get(route('customer.categories.show', $category))->assertNotFound();
    }

    public function test_a_category_from_another_vertical_is_a_404(): void
    {
        $category = $this->makeCategory(['module' => 'hotel']);

        $this->get(route('customer.categories.show', $category))->assertNotFound();
    }

    // ==================== Options ====================

    public function test_option_groups_render_with_their_real_price_deltas(): void
    {
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);
        $this->makeOptionGroup($service, ['1 AC' => 0, '2 ACs' => 450], required: true, name: 'Number of units');

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSee('Number of units')
            ->assertSee('1 AC')
            ->assertSee('2 ACs')
            ->assertSee('450.00');
    }

    public function test_a_required_single_choice_group_preselects_its_first_option(): void
    {
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);
        $group = $this->makeOptionGroup($service, ['Small' => 0, 'Large' => 200], required: true);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSet('selected.'.$group->id, $group->options->first()->id)
            ->assertViewHas('estimatedTotal', 500.0);
    }

    public function test_a_required_multi_choice_group_is_not_preselected_and_is_reported_as_outstanding(): void
    {
        // "Pick at least one of these" is a real choice; silently making it
        // for the customer would be worse than saying it is outstanding.
        $service = $this->makeService($this->makeCategory());
        $this->makeOptionGroup($service, ['A' => 100, 'B' => 200], required: true, multiple: true, name: 'Extras');

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSet('selected', [])
            ->assertSee('Choose Extras to complete this estimate');
    }

    public function test_selecting_an_option_recomputes_the_estimate_on_the_server(): void
    {
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);
        $group = $this->makeOptionGroup($service, ['1 AC' => 0, '2 ACs' => 450], required: true);
        $twoAcs = $group->options->firstWhere('name', '2 ACs');

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertViewHas('estimatedTotal', 500.0)
            ->call('selectOption', $group->id, $twoAcs->id)
            ->assertViewHas('estimatedTotal', 950.0);
    }

    public function test_a_multi_choice_group_toggles_options_in_and_out(): void
    {
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);
        $group = $this->makeOptionGroup($service, ['Coil clean' => 249, 'Deep rinse' => 199], multiple: true);
        $coil = $group->options->firstWhere('name', 'Coil clean');
        $rinse = $group->options->firstWhere('name', 'Deep rinse');

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->call('toggleOption', $group->id, $coil->id)
            ->assertViewHas('estimatedTotal', 749.0)
            ->call('toggleOption', $group->id, $rinse->id)
            ->assertViewHas('estimatedTotal', 948.0)
            ->call('toggleOption', $group->id, $coil->id)
            ->assertViewHas('estimatedTotal', 699.0);
    }

    /**
     * The validation boundary: an option id that belongs to a DIFFERENT
     * service must never reach the estimate, however it arrives.
     */
    public function test_an_option_belonging_to_another_service_cannot_be_selected(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['base_price' => 500]);
        $group = $this->makeOptionGroup($service, ['Standard' => 0], required: true);

        $otherService = $this->makeService($category);
        $otherGroup = $this->makeOptionGroup($otherService, ['Expensive Extra' => 9999]);
        $foreignOption = $otherGroup->options->first();

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->call('selectOption', $group->id, $foreignOption->id)
            ->assertViewHas('estimatedTotal', 500.0);
    }

    public function test_a_forged_selection_payload_cannot_change_the_price(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['base_price' => 500]);

        $otherGroup = $this->makeOptionGroup($this->makeService($category), ['Free Money' => -400]);

        // Set the public property directly — exactly what a tampered Livewire
        // payload does. selectedOptions() re-reads every id against THIS
        // service's own groups, so nothing here reaches the total.
        Livewire::test(ServiceShow::class, ['service' => $service])
            ->set('selected', [$otherGroup->id => $otherGroup->options->first()->id])
            ->assertViewHas('estimatedTotal', 500.0);
    }

    public function test_an_inactive_option_is_not_offered(): void
    {
        $service = $this->makeService($this->makeCategory());
        $group = $this->makeOptionGroup($service, ['Available' => 100, 'Withdrawn' => 200]);
        ServiceOption::where('service_option_group_id', $group->id)->where('name', 'Withdrawn')->update(['is_active' => false]);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSee('Available')
            ->assertDontSee('Withdrawn');
    }

    public function test_a_service_with_no_options_renders_without_a_configuration_section(): void
    {
        $service = $this->makeService($this->makeCategory(), ['name' => 'Simple Service', 'base_price' => 300]);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSee('Simple Service')
            ->assertDontSee('Configure your service')
            ->assertViewHas('estimatedTotal', 300.0);
    }

    // ==================== Pricing context ====================

    public function test_the_estimate_starts_from_the_franchise_resolved_price(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);

        \App\Models\FranchiseServicePricing::create([
            'franchise_id' => $franchise->id, 'service_id' => $service->id,
            'price_override' => 400, 'is_offered' => true,
        ]);

        $group = $this->makeOptionGroup($service, ['Base' => 0, 'Upgrade' => 100], required: true);
        $upgrade = $group->options->firstWhere('name', 'Upgrade');

        app(CustomerLocationContext::class)->setZone($zone->id);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertViewHas('estimatedTotal', 400.0)
            ->call('selectOption', $group->id, $upgrade->id)
            ->assertViewHas('estimatedTotal', 500.0);
    }

    public function test_a_quote_on_inspection_service_says_its_price_may_change(): void
    {
        $service = $this->makeService($this->makeCategory(), ['price_type' => 'quote_on_inspection']);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSee('Starts from')
            ->assertSee('may change after the professional inspects the job');
    }

    // ==================== Ratings and reviews ====================

    public function test_a_service_with_no_reviews_shows_no_rating(): void
    {
        $service = $this->makeService($this->makeCategory(), ['name' => 'Unrated Service']);

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertSee('Unrated Service')
            ->assertDontSee('out of 5')
            ->assertDontSee('What customers said');
    }

    public function test_a_rating_is_averaged_from_reviews_reached_through_bookings(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $booking = $scenario['booking'];
        $booking->update(['status' => 'completed']);

        Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $scenario['customer']->id,
            'provider_id' => $scenario['provider']->id,
            'rating' => 4,
            'comment' => 'Did the job properly.',
        ]);

        Livewire::test(ServiceShow::class, ['service' => $scenario['service']])
            ->assertSee('Rated 4 out of 5')
            ->assertSee('What customers said')
            ->assertSee('Did the job properly.');
    }

    public function test_a_review_with_no_written_comment_counts_towards_the_average_but_is_not_listed(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $booking = $scenario['booking'];
        $booking->update(['status' => 'completed']);

        Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $scenario['customer']->id,
            'provider_id' => $scenario['provider']->id,
            'rating' => 5,
            'comment' => null,
        ]);

        Livewire::test(ServiceShow::class, ['service' => $scenario['service']])
            ->assertSee('Rated 5 out of 5')
            ->assertDontSee('What customers said');
    }

    // ==================== Availability ====================

    public function test_availability_is_not_claimed_when_no_area_has_been_chosen(): void
    {
        $service = $this->makeService($this->makeCategory());

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->assertViewHas('availableProviderCount', null)
            ->assertSee('Set your area to see availability');
    }
}
