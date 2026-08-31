<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Homepage header + hero:
 *  - search is a PERSISTENT field in the header (Urban Company parity): a
 *    compact box in the bar from `sm` up, plus a full-width row under `sm`.
 *    No icon toggle, no reveal drawer. Still none in the homepage hero.
 *  - the hero is a headline + one supporting line + the location action,
 *    laid out beside the category grid on desktop; exactly one <h1>
 *  - DOM order: paid banner first, then the discovery hero headline, then
 *    the category grid (all before the first service rail)
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

    public function test_the_header_renders_two_persistent_search_bars_and_no_toggle(): void
    {
        $html = $this->homeHtml();

        // The <form> root of App\Livewire\Customer\SearchBar carries
        // data-search-bar. Two persistent instances now: the compact field
        // in the bar (sm+) and the full-width row under sm.
        $this->assertSame(
            2,
            substr_count($html, 'data-search-bar'),
            'The header should render two persistent SearchBar islands (bar field + mobile row).',
        );

        // The reveal drawer and its toggle button are gone.
        $this->assertStringNotContainsString('data-search-toggle', $html);
        $this->assertStringNotContainsString('data-search-drawer', $html);

        // Both instances live in the header, before <main>.
        $beforeMain = Str::before($html, '<main');
        $this->assertSame(2, substr_count($beforeMain, 'data-search-bar'));
    }

    public function test_the_homepage_hero_has_no_search_box_of_its_own(): void
    {
        $html = $this->homeHtml();

        $main = Str::between($html, '<main', '</main>');

        $this->assertStringNotContainsString('data-search-bar', $main);
        $this->assertStringNotContainsString('data-search-input', $main);
    }

    public function test_the_hero_has_one_h1_and_not_the_pre_redesign_copy(): void
    {
        $html = $this->homeHtml();

        $this->assertStringNotContainsString('What do you need help with?', $html);
        $this->assertStringNotContainsString('Verified professionals for repairs, installation and maintenance', $html);

        $this->assertStringContainsString('Home services, on call', $html);

        // Exactly one <h1> in the document body — the category grid's own
        // heading (when categories exist) is a visually-hidden <h2>.
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_dom_order_is_banner_then_hero_headline_then_category_grid(): void
    {
        $this->makeBanner('top', ['title' => 'Hero Ad Banner']);
        $category = $this->makeCategory(['name' => 'Cleaning Cat']);
        $this->makeService($category, ['name' => 'Some Service']);

        $this->get(route('customer.home'))
            ->assertOk()
            ->assertSeeInOrder([
                'Hero Ad Banner',          // paid top banner slot
                'Home services, on call',  // discovery hero headline (left column)
                'shortcuts-heading',       // category grid (right column)
                'New &amp; noteworthy',    // first service rail comes after
            ], escape: false);
    }
}
