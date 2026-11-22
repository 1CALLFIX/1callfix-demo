<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSections\Manage;
use App\Models\ActivityLog;
use App\Support\HomeLayout;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Owner 2026-11-22: the homepage "Get the app" strip shows a slogan in its middle ("Why search here and there? Just
 * 1CallFix!" / "One call… does it all."), both lines editable in English and Telugu by the Super Admin, who can switch
 * back to the business names.
 */
class GetAppSloganTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_the_strip_shows_the_slogan_by_default(): void
    {
        $this->assertSame('slogan', HomeLayout::getAppMiddle());
        $this->get('/')->assertOk()
            ->assertSee('data-testid="get-app-slogan"', false)
            ->assertSee('Why search here and there? Just 1CallFix!')
            ->assertSee('One call… does it all.')
            ->assertDontSee('data-testid="get-app-modules"', false);
    }

    public function test_super_admin_edits_the_slogan_and_can_switch_back_to_business_names(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSee('Get the app strip: the middle')
            ->assertSet('slogan.slogan1_en', 'Why search here and there? Just 1CallFix!')
            ->set('slogan.slogan1_en', 'Why roam around? <b>Just</b> 1CallFix')
            ->set('slogan.slogan2_te', 'ఒక్క కాల్ చాలు!')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Why roam around? <b>Just</b> 1CallFix', SiteText::saved('get_app.slogan1', 'en'));
        $this->assertNull(SiteText::saved('get_app.slogan2', 'en'), 'an unchanged line keeps the built-in words, no saved copy');
        $this->assertTrue(ActivityLog::where('description', 'Setting '.SiteText::settingKey('get_app.slogan1', 'en').' changed')->exists());

        $this->get('/')->assertOk()
            ->assertSee('Why roam around? &lt;b&gt;Just&lt;/b&gt; 1CallFix', false)
            ->assertSee('One call… does it all.');

        Livewire::actingAs($admin)->test(Manage::class)->set('slogan.slogan1_en', str_repeat('x', 81))->call('save')->assertHasErrors('slogan.*');
        Livewire::actingAs($admin)->test(Manage::class)->set('getAppMiddle', 'other')->call('save')->assertHasErrors('getAppMiddle');

        Livewire::actingAs($admin)->test(Manage::class)->set('getAppMiddle', 'businesses')
            ->assertDontSee('data-testid="slogan-fields"', false)->call('save')->assertHasNoErrors();

        $this->get('/')->assertOk()
            ->assertSee('data-testid="get-app-modules"', false)
            ->assertSee('Local shopping')
            ->assertDontSee('data-testid="get-app-slogan"', false);
    }
}
