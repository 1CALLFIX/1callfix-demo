<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Partner\ApplyForm;
use App\Livewire\PartnerPage\Settings as Screen;
use App\Models\PartnerBenefit;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001: wording on /partners and its end screens matches what the app really does. */
class PartnerWordingTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private const WAITLIST_SENTENCE = 'We have saved your details. We will contact you on your mobile number when this role opens in your city.';

    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    public function test_the_waitlist_note_above_the_form_and_the_end_screen_default_to_the_same_sentence(): void
    {
        $this->assertSame(self::WAITLIST_SENTENCE, P::text('form.waitlist_note'));
        $this->assertSame(self::WAITLIST_SENTENCE, P::text('form.done_body'));

        Livewire::test(ApplyForm::class)->set('role', 'parcel')
            ->assertSee(self::WAITLIST_SENTENCE)
            ->assertDontSee('we will tell you when it opens in your city');
    }

    public function test_an_admin_edit_changes_the_waitlist_note(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Screen::class)
            ->set('f.form__waitlist_note', 'Your details are saved. Our team will call you when this role opens.')
            ->call('save')->assertHasNoErrors();

        Livewire::test(ApplyForm::class)->set('role', 'parcel')
            ->assertSee('Your details are saved. Our team will call you when this role opens.')
            ->assertDontSee(self::WAITLIST_SENTENCE);
    }

    private function submitted(string $role)
    {
        return Livewire::test(ApplyForm::class)
            ->set('role', $role)->set('name', 'Ramesh Kumar')->set('phone', '9876543210')
            ->set('cityChoice', ApplyForm::OTHER_CITY)->set('city', 'Nellore')->set('consent', true)
            ->call('submit')->assertHasNoErrors();
    }

    public function test_the_service_end_screen_points_to_the_sign_up_page_not_the_app(): void
    {
        $this->submitted('service')->assertSet('outcome', 'handoff')
            ->assertSee('Saved. Continue to sign-up.')
            ->assertSee('Continue to sign-up')
            ->assertSee('Add another application')
            ->assertSee('Thanks, Ramesh. Next, verify your mobile number with a one-time code and upload your documents on the sign-up page.')
            ->assertDontSee('in the app')
            ->assertDontSee('app sign-up');
    }

    public function test_the_form_button_and_the_page_never_say_in_the_app_for_sign_up(): void
    {
        Livewire::test(ApplyForm::class)->assertSee('Continue to sign-up')->assertDontSee('Continue in the app');

        $text = strip_tags($this->get(route('customer.partners'))->assertOk()->getContent());
        foreach (['Continue in the app', 'app sign-up', 'In the app sign-up', 'continue in the 1CallFix app'] as $old) {
            $this->assertStringNotContainsStringIgnoringCase($old, $text);
        }
        $this->assertStringContainsString('sign-up page', $text);
    }

    public function test_the_joining_free_claim_and_the_fee_line_are_one_toggle_off_by_default(): void
    {
        $this->assertFalse(P::SWITCHES['claims.joining_free'][1]);

        $text = strip_tags($this->get(route('customer.partners'))->assertOk()->getContent());
        $this->assertStringNotContainsString('Joining is free', $text);
        $this->assertStringNotContainsString('No fee to apply', $text);

        \App\Models\Setting::set(P::key('claims.joining_free'), '1');
        $text = strip_tags($this->get(route('customer.partners'))->assertOk()->getContent());
        $this->assertStringContainsString('Joining is free. No fee to apply.', $text);
        $this->assertSame(2, substr_count($text, 'No fee to apply'), 'the claim shows in the hero and in the join list, never doubled');
    }

    public function test_the_whatsapp_and_save_for_later_claims_default_off_and_render_nothing_until_switched_on(): void
    {
        foreach (['claims.whatsapp_updates', 'claims.save_finish_later'] as $claim) {
            $this->assertFalse(P::SWITCHES[$claim][1], $claim.' must default OFF in code');
            $this->assertNull(\App\Models\Setting::get(P::key($claim)), $claim.' has no stored value on a fresh install');
        }

        $text = strip_tags($this->get(route('customer.partners'))->assertOk()->getContent());
        foreach (['We send you updates on WhatsApp.', 'You can save your sign-up and finish it later.', 'Save your progress and finish later'] as $line) {
            $this->assertStringNotContainsString($line, $text);
        }

        \App\Models\Setting::set(P::key('claims.whatsapp_updates'), '1');
        $text = strip_tags($this->get(route('customer.partners'))->assertOk()->getContent());
        $this->assertStringContainsString('We send you updates on WhatsApp.', $text);
        $this->assertStringNotContainsString('You can save your sign-up and finish it later.', $text);

        \App\Models\Setting::set(P::key('claims.save_finish_later'), '1');
        $text = strip_tags($this->get(route('customer.partners'))->assertOk()->getContent());
        $this->assertStringContainsString('You can save your sign-up and finish it later.', $text);
    }
}
