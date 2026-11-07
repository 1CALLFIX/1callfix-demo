<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Account\Saved;
use App\Models\SavedItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Look Phase 2 (owner 2026-11-07): a trust strip on every service page, one card style everywhere, tabs on Saved.
 */
class ModuleLookPhase2Test extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_a_service_page_shows_the_trust_strip_and_its_card_style(): void
    {
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Trust Check']);
        $this->makeService($category, ['name' => 'Trust Neighbour']);
        $html = $this->get(\App\Support\Seo\PublicUrl::service($service))->assertOk()->getContent();

        $this->assertStringContainsString('data-trust-strip', $html);
        $this->assertStringContainsString('Verified professionals', $html);
        $this->assertStringContainsString('Your OTP starts the job', $html);
        $this->assertStringContainsString('Rate every job', $html);
        $this->assertStringContainsString('cf-card', $html, 'related cards use the one card style');
    }

    public function test_trust_strip_words_are_super_admin_text(): void
    {
        foreach (['verified', 'otp', 'rate'] as $k) {
            $this->assertArrayHasKey('trust.'.$k, \App\Support\SiteText::TEXTS);
            $this->assertArrayHasKey('trust.'.$k.'_hint', \App\Support\SiteText::TEXTS);
        }
    }

    public function test_saved_tabs_show_only_kinds_you_saved_and_filter_the_list(): void
    {
        $me = $this->makeCustomer();
        SavedItem::create(['user_id' => $me->id, 'item_key' => 'service:1', 'title' => 'Saved AC Service', 'url' => '/a']);
        SavedItem::create(['user_id' => $me->id, 'item_key' => 'product:2', 'title' => 'Saved Biryani', 'url' => '/b']);

        Livewire::actingAs($me)->test(Saved::class)
            ->assertSee('data-saved-tabs', false)
            ->assertSee('Products &amp; dishes', false)
            ->assertDontSee('Shops &amp; restaurants', false)
            ->assertSee('Saved AC Service')->assertSee('Saved Biryani')
            ->call('pickTab', 'services')
            ->assertSee('Saved AC Service')->assertDontSee('Saved Biryani')
            ->call('pickTab', 'nonsense')
            ->assertSee('Saved Biryani');
    }

    public function test_phones_get_footer_links_in_the_menu_and_a_short_footer(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-footer-column', $html, 'columns stay in the page for desktop and search engines');
        $this->assertStringContainsString('class="hidden sm:block" data-footer-column', $html, 'hidden on phones');
        $this->assertStringContainsString('data-menu-more', $html, 'the same links sit in the phone menu');
        $this->assertStringContainsString('All rights reserved.', $html);
    }

    public function test_inside_the_app_the_footer_has_no_link_columns(): void
    {
        $html = $this->withHeader('User-Agent', 'Mozilla/5.0 1CallFixApp')->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-footer-column', $html);
        $this->assertStringContainsString('data-menu-more', $html);
        $this->assertStringContainsString('All rights reserved.', $html);
    }

    public function test_one_kind_saved_shows_no_tabs(): void
    {
        $me = $this->makeCustomer();
        SavedItem::create(['user_id' => $me->id, 'item_key' => 'store:3', 'title' => 'Saved Shop', 'url' => '/c']);

        Livewire::actingAs($me)->test(Saved::class)->assertSee('Saved Shop')->assertDontSee('data-saved-tabs', false);
        $this->assertSame('shops', Saved::tabFor('store:3'));
        $this->assertNull(Saved::tabFor('weird:1'));
    }
}
