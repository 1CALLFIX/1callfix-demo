<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\SiteControls\Manage;
use App\Models\ActivityLog;
use App\Models\HomeSpotlight;
use App\Models\Setting;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * REF 1CF-HOME-CATEGORY-HERO-001 — owner brief 2026-10-15: the homepage opens on a headline and the category icons in
 * rows; the photo tiles are off unless the Super Admin turns them on; pictures are never cropped or zoomed by default.
 */
class HomeCategoryHeroTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_home_opens_on_the_headline_and_category_icons_without_photo_tiles(): void
    {
        [$category, $service] = $this->makeCategoryAndService();
        HomeSpotlight::create(['position' => 1, 'target_type' => 'service', 'target_id' => $service->id, 'is_active' => true]);
        HomeSpotlight::create(['position' => 2, 'target_type' => 'category', 'target_id' => $category->id, 'is_active' => true]);

        $this->get('/')->assertOk()
            ->assertSee(SiteControls::DEFAULT_HEADLINE)
            ->assertSee('cf-cat-grid', false)
            ->assertSee('--cf-cols-m: 4; --cf-cols-d: 8;', false)
            ->assertDontSee('aria-label="Featured services"', false);
    }

    public function test_the_admin_switches_shape_the_opener(): void
    {
        [$category, $service] = $this->makeCategoryAndService();
        $service->update(['image' => 'services/x.png']);
        HomeSpotlight::create(['position' => 1, 'target_type' => 'service', 'target_id' => $service->id, 'is_active' => true]);
        HomeSpotlight::create(['position' => 2, 'target_type' => 'category', 'target_id' => $category->id, 'is_active' => true]);

        Setting::set(SiteControls::HOME_SHOW_PHOTOS, '1');
        Setting::set(SiteControls::HOME_HEADLINE, 'Everything fixed, one call');
        Setting::set(SiteControls::HOME_COLUMNS_MOBILE, '3');
        Setting::set(SiteControls::HOME_COLUMNS_DESKTOP, '6');

        $this->get('/')->assertOk()
            ->assertSee('Everything fixed, one call')
            ->assertSee('--cf-cols-m: 3; --cf-cols-d: 4;', false) // beside the photo tiles the grid has half the width
            ->assertSee('aria-label="Featured services"', false);
    }

    public function test_pictures_are_whole_by_default_and_cover_only_when_chosen(): void
    {
        $this->makeCategoryAndService();

        $this->get('/')->assertSee('object-contain', false)->assertSee('cf-img-border', false)->assertSee('cf-img-gloss', false);

        Setting::set(SiteControls::IMAGE_FIT, 'cover');
        Setting::set(SiteControls::IMAGE_BORDER, '0');
        Setting::set(SiteControls::IMAGE_GLOSS, '0');

        $this->get('/')->assertDontSee('object-contain', false)->assertDontSee('cf-img-border', false)->assertDontSee('cf-img-gloss', false);
    }

    public function test_only_a_super_admin_can_open_the_controls(): void
    {
        $editor = $this->makeUserWithPermission('settings.manage', 'global');
        Livewire::actingAs($editor)->test(Manage::class)->assertForbidden();

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.site-controls'))->assertOk()->assertSee('Homepage &amp; sign-up', false);
    }

    public function test_saving_writes_settings_with_an_audit_trail_and_validates_ranges(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('columnsMobile', '9')->call('save')->assertHasErrors('columnsMobile');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('headline', 'Fixed in one call')
            ->set('showPhotoTiles', true)
            ->set('imageFit', 'cover')
            ->set('servicesStep', 'optional')
            ->set('partnerFormPerMobile', '20')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Fixed in one call', Setting::get(SiteControls::HOME_HEADLINE));
        $this->assertSame('1', Setting::get(SiteControls::HOME_SHOW_PHOTOS));
        $this->assertSame('cover', Setting::get(SiteControls::IMAGE_FIT));
        $this->assertSame('optional', Setting::get(SiteControls::SIGNUP_SERVICES));
        $this->assertSame('20', Setting::get(SiteControls::LIMIT_PARTNER_FORM));
        $this->assertTrue(ActivityLog::where('description', 'Setting '.SiteControls::IMAGE_FIT.' changed')->exists());
    }
}
