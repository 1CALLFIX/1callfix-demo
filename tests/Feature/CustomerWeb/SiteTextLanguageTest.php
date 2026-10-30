<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\SiteText\Manage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteControls;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner rule 2026-10-31: every customer word is the Super Admin's, in English and Telugu; Telugu off until switched on. */
class SiteTextLanguageTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_telugu_is_off_by_default_so_the_site_is_english_with_no_switch(): void
    {
        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()
            ->assertSee('Help Center')
            ->assertDontSee('సహాయ కేంద్రం')
            ->assertDontSee('data-testid="language-switch"', false)
            ->assertSee('<html lang="en"', false);
    }

    public function test_once_switched_on_the_customer_chooses_telugu_and_it_is_remembered(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');

        $this->get('/')->assertOk()->assertSee('data-testid="language-switch"', false)->assertSee('తెలుగు');

        $this->from('/')->post(route('customer.language', 'te'))->assertRedirect('/')->assertCookie(SiteText::COOKIE, 'te');

        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()
            ->assertSee('<html lang="te"', false)
            ->assertSee('సహాయ కేంద్రం')
            ->assertSee('భాగస్వామిగా చేరండి')
            ->assertSee('English');

        $this->post(route('customer.language', 'fr'))->assertNotFound();
    }

    public function test_the_first_time_language_and_saved_texts_win_over_the_defaults(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        Setting::set(SiteText::DEFAULT_LOCALE, 'te');
        Setting::set('text.header.menu.help.te', 'సహాయం');
        Setting::set('text.header.menu.help.en', 'Help & support');

        $this->get('/')->assertOk()->assertSee('సహాయం')->assertDontSee('సహాయ కేంద్రం');
        $this->withCookie(SiteText::COOKIE, 'en')->get('/')->assertOk()->assertSee('Help &amp; support', false);
    }

    public function test_the_old_partner_label_field_stays_the_english_value(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        Setting::set(SiteControls::HEADER_PARTNER_LABEL, 'Join us');

        $this->get('/')->assertOk()->assertSee('Join us');
        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()->assertSee('భాగస్వామిగా చేరండి')->assertDontSee('Join us');
        $this->assertSame(SiteControls::HEADER_PARTNER_LABEL, SiteText::settingKey('header.partner', 'en'));
    }

    public function test_staff_panels_stay_english_even_with_the_telugu_cookie(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');

        $this->actingAs($this->makeSuperAdmin())->withCookie(SiteText::COOKIE, 'te')
            ->get(route('admin.site-text'))->assertOk()->assertSee('<html lang="en"', false);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_only_a_super_admin_edits_the_texts_and_every_change_is_audited(): void
    {
        $customer = $this->makeUserWithNoPermissions();
        $this->actingAs($customer)->get(route('admin.site-text'))->assertForbidden();

        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin);
        Livewire::test(Manage::class)
            ->assertSee('Website text &amp; language', false)
            ->set('teluguEnabled', true)
            ->set('values.header__menu__help.te', 'సహాయం')
            ->set('values.header__partner.en', 'Partner with us')
            ->call('save')
            ->assertSee('Saved 3 change(s).');

        $this->assertSame('1', Setting::get(SiteText::TELUGU_ENABLED));
        $this->assertSame('సహాయం', Setting::get('text.header.menu.help.te'));
        $this->assertSame('Partner with us', Setting::get(SiteControls::HEADER_PARTNER_LABEL), 'the old field is still the English one');
        $this->assertTrue(ActivityLog::where('description', 'Setting text.header.menu.help.te changed')->exists());

        Livewire::test(Manage::class)->set('values.header__menu__help.te', '')->call('save')->assertSee('Saved 1 change(s).');
        $this->assertNull(Setting::get('text.header.menu.help.te'), 'blank goes back to the default');

        Livewire::test(Manage::class)->set('search', 'help')->assertSee('header.menu.help')->assertDontSee('nav.account');
    }
}
