<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Models\Module;
use App\Models\PartnerLead;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001, B3: lead capture and the hand-off into the existing provider sign-up. */
class PartnerLeadCaptureTest extends TestCase
{
    use BookingFixtureHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        \App\Models\City::create(['country_id' => \App\Models\Country::create(['name' => 'Testland', 'code' => 'TL', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true])->id, 'name' => 'Nellore', 'slug' => 'nellore', 'is_active' => true]);
        RateLimiter::clear('x');
    }

    private function fill(string $role = 'service', ?string $phone = null)
    {
        return Livewire::test(ApplyForm::class)
            ->set('role', $role)->set('name', 'Ramesh Kumar')->set('phone', $phone ?? $this->randomPhone())
            ->set('cityChoice', 'nellore')->set('consent', true);
    }

    public function test_the_form_is_on_the_page_with_a_csrf_protected_livewire_endpoint(): void
    {
        $this->get(route('customer.partners'))->assertOk()->assertSeeHtml('wire:submit="submit"')->assertSee('Mobile number');
    }

    public function test_a_service_lead_is_saved_server_side_and_handed_off_with_safe_session_values_only(): void
    {
        $phone = $this->randomPhone();
        $c = $this->fill('service', $phone)->call('submit')->assertHasNoErrors()->assertSet('outcome', 'handoff')
            ->assertSee('Continue in the app')->assertSeeHtml(route('provider.register'));

        $lead = PartnerLead::sole();
        $this->assertSame(PartnerLead::STATUS_HANDED_OFF, $lead->status);
        $this->assertSame($phone, $lead->phone);
        $this->assertNotNull($lead->consent_at);
        $this->assertSame('partner_page', $lead->source);
        $this->assertSame(['id' => $lead->id, 'role' => 'service', 'city' => 'nellore'], session('partner_lead'));
        $this->assertStringNotContainsString($phone, json_encode(session('partner_lead')));
    }

    public function test_a_role_that_is_not_live_is_a_waitlist_entry_with_no_continue_button(): void
    {
        $this->fill('taxi')->call('submit')->assertHasNoErrors()->assertSet('outcome', 'waitlist')
            ->assertDontSee('Continue in the app')->assertSee('You are on the waitlist');

        $this->assertSame(PartnerLead::STATUS_WAITLIST, PartnerLead::sole()->status);
        $this->assertNull(session('partner_lead'));
    }

    public function test_a_live_non_service_module_is_still_a_waitlist_entry_not_a_handoff(): void
    {
        Module::where('code', 'parcel')->update(['is_implemented' => true]);
        $country = \App\Models\Country::create(['name' => 'T', 'code' => 'ZY', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        app(\App\Services\ModuleActivationService::class)->setActive('parcel', 'country', $country->id, true);

        $this->fill('parcel')->call('submit')->assertSet('outcome', 'waitlist')->assertDontSee('Continue in the app');
        $this->assertSame(PartnerLead::STATUS_WAITLIST, PartnerLead::sole()->status);
    }

    public function test_a_client_cannot_choose_the_status_or_an_unknown_or_hidden_role(): void
    {
        $this->fill('service')->call('submit');
        $this->assertSame(PartnerLead::STATUS_HANDED_OFF, PartnerLead::sole()->status);

        Livewire::test(ApplyForm::class)->set('role', 'god_mode')->set('name', 'Aa Bb')->set('phone', $this->randomPhone())->set('cityChoice', 'nellore')->set('consent', true)
            ->call('submit')->assertHasErrors(['role']);

        Setting::set(P::key('modules_hidden'), json_encode(['taxi']));
        $this->fill('taxi')->call('submit')->assertHasErrors(['role']);
        $this->assertSame(1, PartnerLead::count());
    }

    public function test_status_cannot_be_overwritten_through_the_locked_outcome(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $this->fill('taxi')->set('outcome', 'handoff');
    }

    public function test_validation_runs_server_side(): void
    {
        Livewire::test(ApplyForm::class)->set('role', '')->call('submit')->assertHasErrors(['role', 'name', 'phone', 'cityChoice', 'consent']);
        $this->fill()->set('phone', '12345')->call('submit')->assertHasErrors(['phone']);
        $this->fill()->set('name', '<script>')->call('submit')->assertHasErrors(['name']);
        $this->fill()->set('consent', false)->call('submit')->assertHasErrors(['consent']);
        $this->assertSame(0, PartnerLead::count());
    }

    public function test_a_repeat_submit_updates_one_row_and_keeps_the_first_attribution(): void
    {
        $phone = $this->randomPhone();
        $base = ['name' => 'Ramesh Kumar', 'phone' => $phone, 'city' => 'Nellore', 'role' => 'service', 'status' => 'handed_off', 'consent_text' => 'x', 'source' => 'partner_page'];

        PartnerLead::capture($base + ['acquisition' => ['utm_source' => 'google']]);
        PartnerLead::capture(['name' => 'Ramesh K', 'acquisition' => ['utm_source' => 'facebook']] + $base);

        $lead = PartnerLead::sole();
        $this->assertSame(2, $lead->submit_count);
        $this->assertSame('Ramesh K', $lead->name);
        $this->assertSame('google', $lead->acquisition['utm_source']);

        // The form path upserts the same row, and a different role for the same number is a separate row.
        $this->fill('service', $phone)->call('submit');
        $this->assertSame(3, PartnerLead::sole()->submit_count);
        $this->fill('taxi', $phone)->call('submit');
        $this->assertSame(2, PartnerLead::count());

        // A final status is never reopened by a repeat submit.
        PartnerLead::whereKey($lead->id)->update(['status' => 'converted']);
        $this->fill('service', $phone)->call('submit');
        $this->assertSame('converted', $lead->fresh()->status);
    }

    public function test_the_reply_is_identical_whether_or_not_the_number_has_an_account(): void
    {
        $registered = $this->randomPhone();
        User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Existing', 'phone' => $registered, 'role' => 'customer', 'status' => 'active']);
        $fresh = $this->randomPhone();

        $a = $this->fill('service', $registered)->call('submit');
        $b = $this->fill('service', $fresh)->call('submit');

        $a->assertSet('outcome', 'handoff')->assertSet('error', '')->assertHasNoErrors();
        $b->assertSet('outcome', 'handoff')->assertSet('error', '')->assertHasNoErrors();
        $this->assertSame(2, PartnerLead::count());
    }

    public function test_the_honeypot_saves_nothing_and_looks_like_success(): void
    {
        $this->fill('service')->set('website', 'http://spam.example')->call('submit')->assertSet('outcome', 'received')->assertDontSee('Continue in the app');
        $this->assertSame(0, PartnerLead::count());
        $this->assertNull(session('partner_lead'));
    }

    public function test_submits_are_throttled_per_mobile(): void
    {
        $phone = $this->randomPhone();
        for ($i = 0; $i < 3; $i++) {
            $this->fill('service', $phone)->call('submit')->assertHasNoErrors();
        }

        $this->fill('service', $phone)->call('submit')->assertSeeText('Too many attempts');
        $this->assertSame(3, PartnerLead::sole()->submit_count);
    }

    public function test_a_save_failure_shows_only_the_generic_sentence(): void
    {
        \Illuminate\Support\Facades\Schema::drop('partner_leads');

        $this->fill('service')->call('submit')->assertSet('error', 'Something went wrong. Please try again.')->assertSet('outcome', '')
            ->assertDontSee('partner_leads')->assertDontSee('SQLSTATE');
    }

    public function test_the_provider_sign_up_receives_only_safe_values_and_never_trusts_the_phone(): void
    {
        $lead = PartnerLead::capture(['name' => 'Ramesh', 'phone' => $this->randomPhone(), 'city' => 'Nellore', 'role' => 'service', 'status' => 'handed_off', 'consent_text' => 'x', 'acquisition' => null, 'source' => 'partner_page']);

        Livewire::withQueryParams([])->test(Register::class)->assertSet('phone', '')->assertSet('address', '')->assertSet('leadId', null);

        $this->withSession(['partner_lead' => ['id' => $lead->id, 'role' => 'service', 'city' => 'nellore']]);
        Livewire::test(Register::class)->assertSet('leadId', $lead->id)->assertSet('address', 'Nellore')->assertSet('phone', '');

        $this->withSession(['partner_lead' => ['id' => $lead->id, 'role' => 'service', 'city' => '<script>alert(1)</script>']]);
        // An unsafe city value is never used for the prefill; the lead link itself (a server-set id) still holds.
        Livewire::test(Register::class)->assertSet('leadId', $lead->id)->assertSet('address', '');

        $this->withSession(['partner_lead' => ['id' => $lead->id, 'role' => 'taxi', 'city' => 'nellore']]);
        Livewire::test(Register::class)->assertSet('leadId', null);
    }

    public function test_completing_provider_sign_up_marks_the_lead_converted_only_when_the_verified_phone_matches(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 12.9, 'center_lng' => 77.6]);

        $phone = $this->randomPhone();
        $other = PartnerLead::capture(['name' => 'Other', 'phone' => $this->randomPhone(), 'city' => 'Nellore', 'role' => 'service', 'status' => 'handed_off', 'consent_text' => 'x', 'acquisition' => null, 'source' => 'partner_page']);
        $lead = PartnerLead::capture(['name' => 'Ramesh', 'phone' => $phone, 'city' => 'Nellore', 'role' => 'service', 'status' => 'handed_off', 'consent_text' => 'x', 'acquisition' => null, 'source' => 'partner_page']);

        // A session pointing at somebody ELSE's lead must not convert it.
        $this->withSession(['partner_lead' => ['id' => $other->id, 'role' => 'service', 'city' => 'nellore']]);
        $this->register($phone);
        $this->assertSame('handed_off', $other->fresh()->status);

        // The matching lead converts and links the provider.
        $phone2 = $this->randomPhone();
        $lead2 = PartnerLead::capture(['name' => 'Sita', 'phone' => $phone2, 'city' => 'Nellore', 'role' => 'service', 'status' => 'handed_off', 'consent_text' => 'x', 'acquisition' => null, 'source' => 'partner_page']);
        $this->withSession(['partner_lead' => ['id' => $lead2->id, 'role' => 'service', 'city' => 'nellore']]);
        $this->register($phone2);

        $lead2->refresh();
        $this->assertSame(PartnerLead::STATUS_CONVERTED, $lead2->status);
        $this->assertSame(User::where('phone', $phone2)->first()->providerProfile->id, $lead2->provider_id);
        $this->assertSame('handed_off', $lead->fresh()->status);
    }

    private function register(string $phone): void
    {
        $c = Livewire::test(Register::class)->set('phone', $phone);
        $c->call('requestPhoneCode')->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($phone)))->assertSet('step', 'details');
        $c->call('useCurrentLocationForNewAddress', 12.9, 77.6)
            ->set('name', 'Ramesh Fixer')->set('password', 'longenough1')->set('password_confirmation', 'longenough1')
            ->set('address', '4th Cross')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication')->assertSet('submitted', true);
    }

    public function test_the_prune_command_keeps_everything_unless_retention_is_set(): void
    {
        $old = PartnerLead::capture(['name' => 'Old', 'phone' => $this->randomPhone(), 'city' => 'Nellore', 'role' => 'service', 'status' => 'waitlist', 'consent_text' => 'x', 'acquisition' => null, 'source' => 'partner_page']);
        PartnerLead::whereKey($old->id)->update(['updated_at' => now()->subDays(400)]);

        $this->artisan('partner:prune-leads')->assertSuccessful();
        $this->assertSame(1, PartnerLead::count());

        Setting::set(P::key(P::LEAD_RETENTION), '365');
        $this->artisan('partner:prune-leads', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, PartnerLead::count());
        $this->artisan('partner:prune-leads')->assertSuccessful();
        $this->assertSame(0, PartnerLead::count());
    }

    public function test_the_migration_down_refuses_to_drop_a_table_that_holds_leads(): void
    {
        PartnerLead::capture(['name' => 'A', 'phone' => $this->randomPhone(), 'city' => 'Nellore', 'role' => 'service', 'status' => 'new', 'consent_text' => 'x', 'acquisition' => null, 'source' => 'partner_page']);
        $migration = require base_path('database/migrations/2026_10_07_110000_create_partner_leads_table.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
