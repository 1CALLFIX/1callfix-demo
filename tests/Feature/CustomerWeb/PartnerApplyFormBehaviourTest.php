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
            ->assertDontSee('This role is not live yet')
            ->assertSee('Continue in the app');
    }

    public function test_a_role_that_is_not_live_shows_the_waitlist_note_and_waitlist_button(): void
    {
        Livewire::test(ApplyForm::class)
            ->set('role', 'parcel')
            ->assertSee('This role is not live yet')
            ->assertSee('Join the waitlist')
            ->assertDontSee('Continue in the app');
    }

    public function test_add_another_application_returns_to_a_clean_form(): void
    {
        Livewire::test(ApplyForm::class)
            ->set('role', 'parcel')->set('name', 'Asha Rao')->set('phone', '9876543210')->set('city', 'Nellore')->set('consent', true)
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

    public function test_footer_app_buttons_show_only_when_the_admin_sets_a_link(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Get the Android app');

        \App\Models\Setting::set(\App\Support\PartnerPage\PartnerPageSettings::key('store.android_url'), 'https://play.google.com/store/apps/details?id=example');
        $this->get('/')->assertOk()->assertSee('Get the Android app')->assertDontSee('Get the iPhone app');
    }
}
