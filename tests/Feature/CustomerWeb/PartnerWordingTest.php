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
}
