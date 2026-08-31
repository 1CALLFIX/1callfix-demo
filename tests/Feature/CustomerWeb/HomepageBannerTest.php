<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Home;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The two database-driven banner slots on the customer homepage (Phase C).
 *
 * The architecture requirement is that BOTH slots exist, that both are driven
 * entirely by `banners` rows, and that they are INDEPENDENT of one another —
 * so the tests below deliberately put different content in each and assert
 * that neither leaks into the other.
 *
 * Targeting, scheduling and the active flag are all Banner::scopeForSlot()'s
 * job, which existed before Phase C. What is tested here is that the homepage
 * goes through it rather than around it.
 */
class HomepageBannerTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_the_two_slots_render_their_own_banners_and_do_not_leak_into_each_other(): void
    {
        $this->makeBanner('top', ['title' => 'Hero Slot Banner']);
        $this->makeBanner('mid', ['title' => 'Mid Slot Banner']);

        $component = Livewire::test(Home::class);

        $component->assertSee('Hero Slot Banner')->assertSee('Mid Slot Banner');

        $hero = $component->viewData('heroBanners')->pluck('title')->all();
        $mid = $component->viewData('midBanners')->pluck('title')->all();

        $this->assertSame(['Hero Slot Banner'], $hero);
        $this->assertSame(['Mid Slot Banner'], $mid);
    }

    public function test_multiple_banners_in_one_slot_all_render_as_slides(): void
    {
        $this->makeBanner('top', ['title' => 'Slide One', 'sort_order' => 1]);
        $this->makeBanner('top', ['title' => 'Slide Two', 'sort_order' => 2]);
        $this->makeBanner('top', ['title' => 'Slide Three', 'sort_order' => 3]);

        Livewire::test(Home::class)
            ->assertSee('Slide One')
            ->assertSee('Slide Two')
            ->assertSee('Slide Three')
            ->assertSee('Go to slide 3 of 3');
    }

    /**
     * The mandatory fallback: with no live banner, the homepage must render
     * its own discovery hero and NOT an empty carousel shell.
     */
    public function test_no_banner_leaves_no_carousel_and_the_discovery_hero_still_stands(): void
    {
        Livewire::test(Home::class)
            ->assertSee('Home services, on call')
            ->assertDontSee('aria-roledescription="carousel"', escape: false)
            ->assertDontSee('Previous slide');
    }

    public function test_a_single_banner_renders_without_carousel_controls(): void
    {
        // One slide has nothing to page between; arrows, dots and a
        // play/pause control would all be inert chrome.
        $this->makeBanner('top', ['title' => 'Only Banner']);

        Livewire::test(Home::class)
            ->assertSee('Only Banner')
            ->assertDontSee('Previous slide')
            ->assertDontSee('Pause banner rotation');
    }

    // ==================== Scheduling and activation ====================

    public function test_an_inactive_banner_never_renders(): void
    {
        $this->makeBanner('top', ['title' => 'Switched Off', 'is_active' => false]);

        Livewire::test(Home::class)->assertDontSee('Switched Off');
    }

    public function test_a_banner_whose_window_has_closed_never_renders(): void
    {
        $this->makeBanner('top', [
            'title' => 'Expired Banner',
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subDay(),
        ]);

        Livewire::test(Home::class)->assertDontSee('Expired Banner');
    }

    public function test_a_banner_scheduled_for_the_future_does_not_render_yet(): void
    {
        $this->makeBanner('mid', [
            'title' => 'Future Banner',
            'starts_at' => now()->addWeek(),
            'expires_at' => now()->addMonth(),
        ]);

        Livewire::test(Home::class)->assertDontSee('Future Banner');
    }

    // ==================== Targeting ====================

    public function test_a_zone_targeted_banner_only_appears_for_a_customer_in_that_zone(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();

        $this->makeBanner('top', ['title' => 'Zone Banner', 'zone_id' => $zone->id]);

        Livewire::test(Home::class)->assertDontSee('Zone Banner');

        app(CustomerLocationContext::class)->setZone($otherZone->id);
        Livewire::test(Home::class)->assertDontSee('Zone Banner');

        app(CustomerLocationContext::class)->setZone($zone->id);
        Livewire::test(Home::class)->assertSee('Zone Banner');
    }

    public function test_a_franchise_targeted_banner_only_appears_for_that_franchise(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();

        $this->makeBanner('mid', ['title' => 'Franchise Banner', 'franchise_id' => $franchise->id]);

        app(CustomerLocationContext::class)->setZone($otherZone->id);
        Livewire::test(Home::class)->assertDontSee('Franchise Banner');

        app(CustomerLocationContext::class)->setZone($zone->id);
        Livewire::test(Home::class)->assertSee('Franchise Banner');
    }

    /**
     * The customer web app is the Service vertical. A slot sold against
     * Marketplace or Hotel must never surface on it, or an advertiser is
     * paying for impressions in the wrong app.
     */
    public function test_a_banner_targeted_at_another_module_never_appears(): void
    {
        $this->makeBanner('top', ['title' => 'Marketplace Banner', 'module' => 'commerce']);
        $this->makeBanner('top', ['title' => 'Service Banner', 'module' => 'service']);

        Livewire::test(Home::class)
            ->assertSee('Service Banner')
            ->assertDontSee('Marketplace Banner');
    }

    public function test_an_untargeted_banner_appears_for_everyone(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $this->makeBanner('top', ['title' => 'Global Banner']);

        Livewire::test(Home::class)->assertSee('Global Banner');

        app(CustomerLocationContext::class)->setZone($zone->id);
        Livewire::test(Home::class)->assertSee('Global Banner');
    }

    // ==================== Rendering details ====================

    public function test_a_banner_with_no_image_still_renders_as_a_readable_panel(): void
    {
        $this->makeBanner('top', ['title' => 'Imageless Banner', 'image' => '']);

        Livewire::test(Home::class)->assertSee('Imageless Banner');
    }

    /**
     * `banners.link` is admin-editable free text on a public, unauthenticated
     * page. A `javascript:` URL must never reach an href.
     */
    public function test_a_dangerous_banner_link_scheme_is_not_rendered_as_a_link(): void
    {
        $this->makeBanner('top', [
            'title' => 'Suspicious Banner',
            'link' => 'javascript:alert(document.cookie)',
        ]);

        Livewire::test(Home::class)
            ->assertSee('Suspicious Banner')
            ->assertDontSee('javascript:alert', escape: false);
    }

    public function test_a_safe_banner_link_is_rendered(): void
    {
        $this->makeBanner('top', ['title' => 'Linked Banner', 'link' => 'https://example.test/promo']);

        Livewire::test(Home::class)->assertSee('https://example.test/promo', escape: false);
    }

    /**
     * `sort_order` is the admin's manual ordering within a slot. Banner::forSlot()
     * ranks most-specific first, then by sort_order — so among equally-untargeted
     * banners the sort_order sequence is exactly what the slot renders.
     */
    public function test_sort_order_orders_banners_within_a_slot(): void
    {
        $this->makeBanner('top', ['title' => 'Third', 'sort_order' => 30]);
        $this->makeBanner('top', ['title' => 'First', 'sort_order' => 10]);
        $this->makeBanner('top', ['title' => 'Second', 'sort_order' => 20]);

        $order = Livewire::test(Home::class)->viewData('heroBanners')->pluck('title')->all();

        $this->assertSame(['First', 'Second', 'Third'], $order);
    }

    /**
     * The homepage context sets no category_id, so a banner sold against one
     * specific category must not leak onto the home slot — while an untargeted
     * banner in the same slot still shows (the fallback).
     */
    public function test_a_category_targeted_banner_does_not_show_in_the_untargeted_home_context(): void
    {
        $category = $this->makeCategory(['name' => 'AC Repair']);

        $this->makeBanner('mid', ['title' => 'Everywhere Mid']);
        $this->makeBanner('mid', ['title' => 'AC Only Mid', 'category_id' => $category->id]);

        $titles = Livewire::test(Home::class)->viewData('midBanners')->pluck('title')->all();

        $this->assertSame(['Everywhere Mid'], $titles);
    }
}
