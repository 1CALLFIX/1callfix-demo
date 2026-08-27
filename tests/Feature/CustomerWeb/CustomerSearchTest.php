<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Search;
use App\Livewire\Customer\SearchBar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Customer discovery search: the results screen and the header's live
 * suggestion dropdown (Phase C).
 *
 * Both go through App\Services\Catalog\ServiceCatalogQuery — the same class
 * the REST API's catalog endpoints use — so the tests that matter most are
 * the ones proving search cannot surface something the rest of the catalog
 * hides.
 */
class CustomerSearchTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    // ==================== What search matches ====================

    public function test_search_matches_a_service_name(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Deep Cleaning']);
        $this->makeService($category, ['name' => 'Tap Repair']);

        Livewire::test(Search::class)
            ->set('query', 'cleaning')
            ->assertSee('Deep Cleaning')
            ->assertDontSee('Tap Repair');
    }

    public function test_search_matches_a_service_description(): void
    {
        // A customer describes the PROBLEM ("leaking") at least as often as
        // they name the service, so the description is searched too.
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Pipe Work', 'description' => 'For a leaking joint or wall.']);

        Livewire::test(Search::class)
            ->set('query', 'leaking')
            ->assertSee('Pipe Work');
    }

    public function test_search_matches_the_owning_category_name(): void
    {
        $category = $this->makeCategory(['name' => 'Pest Control']);
        $this->makeService($category, ['name' => 'Cockroach Treatment']);

        Livewire::test(Search::class)
            ->set('query', 'pest')
            ->assertSee('Cockroach Treatment');
    }

    public function test_search_matches_the_owning_subcategory_name(): void
    {
        $category = $this->makeCategory(['name' => 'Appliances']);
        $subcategory = $this->makeSubcategory($category, ['name' => 'Refrigeration']);
        $this->makeService($category, ['name' => 'Fridge Visit', 'subcategory_id' => $subcategory->id]);

        Livewire::test(Search::class)
            ->set('query', 'refrigeration')
            ->assertSee('Fridge Visit');
    }

    public function test_matching_categories_are_offered_as_their_own_result_group(): void
    {
        $category = $this->makeCategory(['name' => 'Painting Services']);
        $this->makeService($category, ['name' => 'Wall Painting']);

        Livewire::test(Search::class)
            ->set('query', 'painting')
            ->assertSee('Matching categories')
            ->assertSee('Painting Services');
    }

    // ==================== What search must never surface ====================

    public function test_search_never_surfaces_an_inactive_service(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Hidden Cleaning', 'is_active' => false]);

        Livewire::test(Search::class)
            ->set('query', 'cleaning')
            ->assertDontSee('Hidden Cleaning')
            ->assertSee('Nothing matched');
    }

    public function test_search_never_surfaces_another_verticals_catalog(): void
    {
        $marketplace = $this->makeCategory(['module' => 'commerce', 'name' => 'Cleaning Products']);
        $this->makeService($marketplace, ['name' => 'Cleaning Spray']);

        Livewire::test(Search::class)
            ->set('query', 'cleaning')
            ->assertDontSee('Cleaning Spray')
            ->assertDontSee('Cleaning Products');
    }

    // ==================== Query handling ====================

    public function test_a_term_shorter_than_the_minimum_does_not_run_a_search(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Anything At All']);

        Livewire::test(Search::class)
            ->set('query', 'a')
            ->assertSee('Type at least 2 characters')
            ->assertDontSee('Anything At All');
    }

    /**
     * A LIKE wildcard typed by a customer must be searched for literally.
     * Unescaped, "%" would match the entire catalog and look like a bug.
     */
    public function test_like_wildcards_in_the_term_are_escaped(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Ordinary Service']);
        $this->makeService($category, ['name' => 'Save 50% Service']);

        Livewire::test(Search::class)
            ->set('query', '50%')
            ->assertSee('Save 50% Service')
            ->assertDontSee('Ordinary Service');
    }

    public function test_no_results_states_it_plainly_and_offers_a_way_out(): void
    {
        $this->makeService($this->makeCategory(), ['name' => 'Something Else']);

        Livewire::test(Search::class)
            ->set('query', 'zzzznomatch')
            ->assertSee('Nothing matched')
            ->assertSee('Browse all categories');
    }

    public function test_clearing_the_search_returns_to_the_browse_state(): void
    {
        $this->makeCategory(['name' => 'Browsable Category']);

        Livewire::test(Search::class)
            ->set('query', 'zzzznomatch')
            ->assertSee('Nothing matched')
            ->call('clear')
            ->assertSet('query', '')
            ->assertDontSee('Nothing matched')
            ->assertSee('Browsable Category');
    }

    public function test_the_query_is_shareable_through_the_url(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Linkable Service']);

        Livewire::withQueryParams(['q' => 'Linkable'])
            ->test(Search::class)
            ->assertSet('query', 'Linkable')
            ->assertSee('Linkable Service');
    }

    // ==================== Recent searches ====================

    public function test_a_real_search_is_remembered_and_can_be_reused(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Remembered Service']);

        Livewire::test(Search::class)->set('query', 'remembered');

        $this->assertSame(['remembered'], session(Search::SESSION_RECENT_KEY));

        Livewire::test(Search::class)
            ->assertSee('Recent searches')
            ->call('useRecent', 'remembered')
            ->assertSee('Remembered Service');
    }

    public function test_a_too_short_term_is_never_remembered(): void
    {
        Livewire::test(Search::class)->set('query', 'a');

        $this->assertSame([], session(Search::SESSION_RECENT_KEY, []));
    }

    public function test_repeating_a_search_does_not_duplicate_it_in_the_recent_list(): void
    {
        Livewire::test(Search::class)->set('query', 'plumber')->set('query', 'PLUMBER');

        $this->assertSame(['PLUMBER'], session(Search::SESSION_RECENT_KEY));
    }

    public function test_recent_searches_can_be_cleared(): void
    {
        Livewire::test(Search::class)
            ->set('query', 'electrician')
            ->call('clearRecent');

        $this->assertNull(session(Search::SESSION_RECENT_KEY));
    }

    // ==================== The header suggestion dropdown ====================

    public function test_the_suggestion_dropdown_stays_closed_until_the_term_is_long_enough(): void
    {
        $this->makeService($this->makeCategory(), ['name' => 'Suggested Service']);

        Livewire::test(SearchBar::class)
            ->set('term', 's')
            ->assertSet('showSuggestions', false)
            ->assertDontSee('Suggested Service');
    }

    public function test_the_suggestion_dropdown_lists_matches(): void
    {
        $this->makeService($this->makeCategory(), ['name' => 'Suggested Service']);

        Livewire::test(SearchBar::class)
            ->set('term', 'suggested')
            ->assertSet('showSuggestions', true)
            ->assertSee('Suggested Service')
            ->assertSee('See all results for');
    }

    public function test_the_suggestion_dropdown_says_so_when_nothing_matches(): void
    {
        $this->makeService($this->makeCategory(), ['name' => 'Something Else']);

        Livewire::test(SearchBar::class)
            ->set('term', 'zzzznomatch')
            ->assertSee('Nothing matched')
            ->assertSee('browse the categories');
    }

    public function test_submitting_redirects_to_the_shareable_search_screen(): void
    {
        Livewire::test(SearchBar::class)
            ->set('term', 'ac repair')
            ->call('submit')
            ->assertRedirect(route('customer.search', ['q' => 'ac repair']));
    }

    public function test_submitting_an_empty_term_still_reaches_the_search_screen(): void
    {
        Livewire::test(SearchBar::class)
            ->call('submit')
            ->assertRedirect(route('customer.search'));
    }
}
