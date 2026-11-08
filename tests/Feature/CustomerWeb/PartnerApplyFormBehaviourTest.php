<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Partner\ApplyForm;
use App\Models\PartnerBenefit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001: form behaviour from the design: preselected role, waitlist note, submit label, add another. */
class PartnerApplyFormBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    public function test_the_service_role_is_preselected_and_has_no_waitlist_note(): void
    {
        Livewire::test(ApplyForm::class)
            ->assertSet('role', 'service')
            ->assertDontSee('We have saved your details. We will contact you')
            ->assertSee('Continue to sign-up');
    }

    public function test_a_role_that_is_not_live_shows_the_waitlist_note_and_waitlist_button(): void
    {
        Livewire::test(ApplyForm::class)
            ->set('role', 'parcel')
            ->assertSee('We will contact you on your mobile number when this role opens in your city.')
            ->assertSee('Join the waitlist')
            ->assertDontSee('Continue to sign-up');
    }

    public function test_add_another_application_returns_to_a_clean_form(): void
    {
        Livewire::test(ApplyForm::class)
            ->set('role', 'parcel')->set('name', 'Asha Rao')->set('phone', '9876543210')->set('cityChoice', ApplyForm::OTHER_CITY)->set('city', 'Nellore')->set('consent', true)
            ->call('submit')->assertSet('outcome', 'waitlist')->assertSee('Add another application')
            ->call('again')->assertSet('outcome', '')->assertSet('name', '')->assertSet('phone', '')->assertSet('consent', false);
    }

    public function test_the_page_has_the_section_anchors_the_nav_points_at(): void
    {
        $html = $this->get(route('customer.partners'))->assertOk()->getContent();
        foreach (['id="roles"', 'id="how"', 'id="why"', 'id="faq"', 'id="join"', 'href="#join"', 'href="#roles"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString(route('provider.login'), $html);
    }

    /** Owner 2026-11-08: the Play badge is always there (customer app by default); a typed link wins; iPhone only when typed. */
    public function test_footer_app_badge_uses_the_admin_link_when_set_and_iphone_only_when_typed(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('data-testid="footer-app"', false)
            ->assertSee('https://play.google.com/store/apps/details?id=com.call.customer', false)
            ->assertDontSee('Get the iPhone app');

        \App\Models\Setting::set(\App\Support\PartnerPage\PartnerPageSettings::key('store.android_url'), 'https://play.google.com/store/apps/details?id=example');
        \App\Models\Setting::set(\App\Support\PartnerPage\PartnerPageSettings::key('store.ios_url'), 'https://apps.apple.com/app/id1');
        $this->get('/')->assertOk()
            ->assertSee('https://play.google.com/store/apps/details?id=example', false)
            ->assertSee('Get the iPhone app');
    }

    public function test_required_field_errors_use_plain_wording(): void
    {
        $c = Livewire::test(ApplyForm::class)->call('submit');
        $errors = $c->errors();

        $this->assertSame(['Enter your full name.'], $errors->get('name'));
        $this->assertSame(['Enter your mobile number.'], $errors->get('phone'));
        // No listed cities in this database: the free-text city is the only field, so its message applies.
        $this->assertSame(['Enter your city.'], $errors->get('city'));
        $this->assertSame(['Please tick the box to continue.'], $errors->get('consent'));
    }
}
