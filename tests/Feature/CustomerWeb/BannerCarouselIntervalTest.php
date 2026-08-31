<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Home;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The hero and mid banner slots share ONE carousel component
 * (x-customer.banner-carousel) but rotate at their own configured cadence —
 * hero noticeably slower than mid — set per call site from config/banners.php
 * and passed through to carousel.js as data-carousel-interval. Nothing about
 * the timing is hardcoded in the component or the JS (which only falls back
 * to its own default when handed no interval at all).
 */
class BannerCarouselIntervalTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_the_component_emits_whatever_interval_it_is_given(): void
    {
        $banners = collect([
            $this->makeBanner('top', ['title' => 'A']),
            $this->makeBanner('top', ['title' => 'B']),
        ]);

        $this->blade('<x-customer.banner-carousel :banners="$banners" id="hero" :interval="9000" />', compact('banners'))
            ->assertSee('data-carousel-interval="9000"', false);

        $this->blade('<x-customer.banner-carousel :banners="$banners" id="mid" :interval="3500" />', compact('banners'))
            ->assertSee('data-carousel-interval="3500"', false);
    }

    public function test_no_interval_prop_leaves_the_component_on_the_js_default(): void
    {
        $banners = collect([
            $this->makeBanner('mid', ['title' => 'A']),
            $this->makeBanner('mid', ['title' => 'B']),
        ]);

        $this->blade('<x-customer.banner-carousel :banners="$banners" id="x" />', compact('banners'))
            ->assertDontSee('data-carousel-interval', false);
    }

    public function test_the_two_homepage_slots_render_with_their_own_configured_speeds(): void
    {
        config(['banners.hero_rotation_ms' => 9000, 'banners.mid_rotation_ms' => 3500]);

        $this->makeBanner('top', ['title' => 'Hero Slide']);
        $this->makeBanner('mid', ['title' => 'Mid Slide']);

        $html = Livewire::test(Home::class)->html();

        // Both configured values are present, so the two call sites are not
        // sharing one speed.
        $this->assertStringContainsString('data-carousel-interval="9000"', $html);
        $this->assertStringContainsString('data-carousel-interval="3500"', $html);
    }

    public function test_the_hero_slot_is_configured_slower_than_the_mid_slot(): void
    {
        $this->assertGreaterThan(
            (int) config('banners.mid_rotation_ms'),
            (int) config('banners.hero_rotation_ms'),
            'The hero banner should rotate slower than the mid-page strip.',
        );
    }
}
