<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\SiteControls\Manage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-22: "the banners look very small … give super admin control". Admin → Homepage & sign-up sets how many
 * homepage top banners sit side by side on computers and on phones; a banner keeps its shape, so fewer = bigger.
 */
class HomeBannerSizeSettingTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function hero(int $count = 3)
    {
        $banners = collect(range(1, $count))->map(fn ($i) => $this->makeBanner('top', ['title' => 'B'.$i]));

        return $this->blade('<x-customer.banner-carousel :banners="$banners" id="hero-banners" label="Featured promotions" variant="hero" />', compact('banners'));
    }

    public function test_nothing_changes_until_the_super_admin_picks_a_size(): void
    {
        $this->assertSame('two_peek', SiteControls::bannerSizeComputer());
        $this->assertSame('peek', SiteControls::bannerSizePhone());
        $this->hero()->assertSee('[data-carousel-slide]{width:85%}', false)->assertSee('[data-carousel-slide]{width:42%}', false);
    }

    public function test_the_chosen_sizes_reach_the_homepage_banners(): void
    {
        Setting::set(SiteControls::BANNER_SIZE_COMPUTER, 'big');
        Setting::set(SiteControls::BANNER_SIZE_PHONE, 'full');

        $this->hero()->assertSee('[data-carousel-slide]{width:100%}', false)->assertSee('[data-carousel-slide]{width:88%}', false)
            ->assertDontSee('{width:42%}', false);
    }

    public function test_two_banners_never_leave_an_empty_third_place(): void
    {
        Setting::set(SiteControls::BANNER_SIZE_COMPUTER, 'three');
        $this->hero(2)->assertSee('{width:calc(50% - 0.5rem)}', false);
        $this->hero(3)->assertSee('{width:calc(33.333% - 0.667rem)}', false);
    }

    public function test_a_single_banner_or_a_strip_is_not_affected(): void
    {
        Setting::set(SiteControls::BANNER_SIZE_COMPUTER, 'three');
        $this->hero(1)->assertDontSee('data-banner-size', false);

        $banners = collect([$this->makeBanner('top', ['title' => 'A']), $this->makeBanner('top', ['title' => 'B'])]);
        $this->blade('<x-customer.banner-carousel :banners="$banners" id="page" variant="strip" />', compact('banners'))->assertDontSee('data-banner-size', false);
    }

    public function test_super_admin_saves_the_sizes_with_an_audit_trail(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSee('Homepage top banners: size')->assertSee('Big: 1 banner, the next one peeking')
            ->set('bannerSizeComputer', 'huge')->call('save')->assertHasErrors('bannerSizeComputer');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('bannerSizeComputer', 'large')->set('bannerSizePhone', 'full')->call('save')->assertHasNoErrors();

        $this->assertSame('large', SiteControls::bannerSizeComputer());
        $this->assertSame('full', SiteControls::bannerSizePhone());
        $this->assertTrue(ActivityLog::where('description', 'Setting '.SiteControls::BANNER_SIZE_COMPUTER.' changed')->exists());
    }
}
