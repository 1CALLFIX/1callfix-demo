<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\SiteControls\Manage;
use App\Models\Setting;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-07: phone visitors are invited to the app (Android → Play Store; iPhone → Add to Home Screen, no iOS app). */
class AppBannerTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_the_website_carries_the_strip_with_the_play_store_link_and_the_iphone_steps(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('data-app-banner', false)
            ->assertSee('https://play.google.com/store/apps/details?id=com.call.customer', false)
            ->assertSee('Get the app')
            ->assertSee('Add to Home Screen')
            ->assertSee('data-hide-days="14"', false);
    }

    public function test_never_inside_the_android_app(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) 1CallFixApp/customer')
            ->get('/')->assertOk()->assertDontSee('data-app-banner', false);
    }

    public function test_the_super_admin_switches_it_off_and_sets_the_days(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)
            ->set('appBannerDays', '0')->call('save')->assertHasErrors('appBannerDays');
        Livewire::test(Manage::class)
            ->set('appBanner', false)->set('appBannerDays', '30')->call('save')->assertHasNoErrors();

        $this->assertSame('0', Setting::get(SiteControls::APP_BANNER));
        $this->assertSame('30', Setting::get(SiteControls::APP_BANNER_DAYS));
        auth()->logout();
        $this->get('/')->assertOk()->assertDontSee('data-app-banner', false);
    }
}
