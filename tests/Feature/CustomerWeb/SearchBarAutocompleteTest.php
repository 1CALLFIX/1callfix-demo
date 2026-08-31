<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\SearchBar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Autocomplete behaviour of the header / hero search dropdown
 * (homepage-search-banners work):
 *
 *  - a typed term returns matching services AND matching categories
 *  - service matches are grouped under their category name
 *  - an empty field on focus shows a sensible default list
 *  - a below-threshold term runs no catalog query (the debounce on the input
 *    is only an optimisation; this length guard is the real one)
 *  - Escape / dismiss close the list
 *
 * The arrow-key cursor itself is JavaScript (resources/js/search-bar.js);
 * what is proven here is the ARIA/markup contract it drives — a
 * role="combobox" input wired to a role="listbox" of role="option" links
 * with stable ids.
 */
class SearchBarAutocompleteTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_a_typed_term_returns_matching_services_and_categories(): void
    {
        $plumbing = $this->makeCategory(['name' => 'Plumbing Services']);
        $this->makeService($plumbing, ['name' => 'Plumbing Leak Fix']);

        $electrical = $this->makeCategory(['name' => 'Electrical Work']);
        $this->makeService($electrical, ['name' => 'Ceiling Fan Install']);

        Livewire::test(SearchBar::class)
            ->set('term', 'plumb')
            ->assertSet('showSuggestions', true)
            ->assertSee('Plumbing Services')   // matched category
            ->assertSee('Plumbing Leak Fix')   // matched service
            ->assertDontSee('Ceiling Fan Install');
    }

    public function test_service_matches_are_grouped_under_their_category(): void
    {
        $ac = $this->makeCategory(['name' => 'AC Repair']);
        $this->makeService($ac, ['name' => 'AC Gas Refill Visit']);

        $appliance = $this->makeCategory(['name' => 'Appliance Care']);
        $this->makeService($appliance, ['name' => 'AC Wall Mount Visit']);

        $component = Livewire::test(SearchBar::class)->set('term', 'AC ');

        $groupNames = $component->viewData('serviceGroups')->pluck('name')->all();

        $this->assertContains('AC Repair', $groupNames);
        $this->assertContains('Appliance Care', $groupNames);

        $component->assertSee('AC Gas Refill Visit')->assertSee('AC Wall Mount Visit');
    }

    public function test_an_empty_field_on_focus_shows_a_sensible_default_list(): void
    {
        $cat = $this->makeCategory(['name' => 'Cleaning']);
        $this->makeService($cat, ['name' => 'Sofa Shampoo']);
        $this->makeService($cat, ['name' => 'Kitchen Deep Clean']);

        Livewire::test(SearchBar::class)
            ->call('focusField')
            ->assertSet('showSuggestions', true)
            ->assertSet('term', '')
            ->assertViewHas('isDefault', true)
            ->assertViewHas('defaultHeading', 'Browse services')
            ->assertSee('Sofa Shampoo')
            ->assertSee('Kitchen Deep Clean');
    }

    public function test_a_below_threshold_term_runs_no_service_or_category_query(): void
    {
        $this->makeService($this->makeCategory(['name' => 'Roofing']), ['name' => 'Roof Patch']);

        DB::enableQueryLog();

        Livewire::test(SearchBar::class)
            ->set('term', 'r')
            ->assertSet('showSuggestions', false)
            ->assertDontSee('Roof Patch');

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsStringIgnoringCase('from "services"', $queries);
        $this->assertStringNotContainsStringIgnoringCase('from "service_categories"', $queries);
    }

    public function test_the_input_debounces_and_exposes_the_combobox_markup_contract(): void
    {
        $this->makeService($this->makeCategory(['name' => 'Gardening']), ['name' => 'Hedge Trim']);

        Livewire::test(SearchBar::class)
            ->set('term', 'hedge')
            ->assertSeeHtml('wire:model.live.debounce.250ms="term"')
            ->assertSeeHtml('role="combobox"')
            ->assertSeeHtml('aria-autocomplete="list"')
            ->assertSeeHtml('data-search-input')
            ->assertSeeHtml('wire:keydown.escape="dismiss"')
            ->assertSeeHtml('role="listbox"')
            ->assertSeeHtml('role="option"')
            ->assertSeeHtml('data-search-option');
    }

    public function test_dismiss_closes_the_list(): void
    {
        $this->makeService($this->makeCategory(), ['name' => 'Gate Repair']);

        Livewire::test(SearchBar::class)
            ->set('term', 'gate')
            ->assertSet('showSuggestions', true)
            ->call('dismiss')
            ->assertSet('showSuggestions', false);
    }
}
