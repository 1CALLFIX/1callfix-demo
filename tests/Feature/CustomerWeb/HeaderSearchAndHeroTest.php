<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Homepage redesign close-out:
 *  - search is icon-triggered at EVERY width — no persistent embedded box in
 *    the header at xl+, and none in the homepage hero
 *  - the hero heading is a single minimal line, not the old
 *    heading + subtext pair
 *  - DOM order: banner first, then the trimmed discovery header, then the
 *    category rail (all before the first service rail)
 */
class HeaderSearchAndHeroTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function homeHtml(): string
    {
        return $this->get(route('customer.home'))->assertOk()->getContent();
    }

    public function test_the_header_renders_exactly_one_search_bar_and_it_is_the_icon_toggled_panel(): void
    {
        $html = $this->homeHtml();

        // The <form> root of App\Livewire\Customer\SearchBar carries data-search-bar.
        $this->assertSame(
            1,
            substr_count($html, 'data-search-bar'),
            'There should be exactly one search-bar instance on the homepage (the header toggle panel).',
        );

        $this->assertStringContainsString('data-search-toggle', $html);
        $this->assertStringContainsString('data-search-drawer', $html);

        // The single instance sits after data-search-drawer, i.e. inside the
        // toggle panel — not as a loose persistent box earlier in the bar.
        $this->assertStringNotContainsString('data-search-bar', Str::before($html, 'data-search-drawer'));
    }

    public function test_the_homepage_hero_has_no_search_box_of_its_own(): void
    {
        $html = $this->homeHtml();

        $main = Str::between($html, '<main', '</main>');

        $this->assertStringNotContainsString('data-search-bar', $main);
        $this->assertStringNotContainsString('data-search-input', $main);
    }

    public function test_the_hero_heading_is_the_trimmed_single_line_not_the_old_pair(): void
    {
        $html = $this->homeHtml();

        $this->assertStringNotContainsString('What do you need help with?', $html);
        $this->assertStringNotContainsString('Verified professionals for repairs, installation and maintenance', $html);

        $this->assertStringContainsString('Home services, on call', $html);

        // Exactly one <h1> in the document body.
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_dom_order_is_banner_then_trimmed_header_then_category_rail(): void
    {
        $this->makeBanner('top', ['title' => 'Hero Ad Banner']);
        $category = $this->makeCategory(['name' => 'Cleaning Cat']);
        $this->makeService($category, ['name' => 'Some Service']);

        $this->get(route('customer.home'))
            ->assertOk()
            ->assertSeeInOrder([
                'Hero Ad Banner',          // top banner slot
                'Home services, on call',  // trimmed discovery header
                'shortcuts-heading',       // category rail
                'New &amp; noteworthy',    // first service rail comes after
            ], escape: false);
    }
}
