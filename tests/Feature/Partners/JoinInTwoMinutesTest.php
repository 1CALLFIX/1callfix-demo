<?php

namespace Tests\Feature\Partners;

use App\Actions\ReviewProviderKycAction;
use App\Livewire\Partner\Join;
use App\Livewire\Provider\Auth\Login as PartnerLogin;
use App\Livewire\Provider\Auth\Register;
use App\Livewire\Seller\Auth\Login as SellerLogin;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Join in 2 minutes (owner 2026-11-12: "every business ... should feel very easy to join apart from all of the
 * aggregators"): apply with a mobile code, name, business and address — no password, no documents; signed straight in
 * to "Finish joining" (documents any time, set-up, approval); every partner sign-in works with a mobile code. Approval
 * still needs every required document. Password and documents-at-sign-up are Super Admin switches.
 */
class JoinInTwoMinutesTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    private function zone(): Zone
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 12.9, 'center_lng' => 77.6]);

        return $zone->fresh();
    }

    /** Mobile code, name, address, terms — nothing else. */
    private function apply(string $phone)
    {
        $c = Livewire::test(Register::class)->set('phone', $phone);
        $c->call('requestPhoneCode')->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($phone)))->assertSet('step', 'details');

        return $c->call('useCurrentLocationForNewAddress', 12.9, 77.6)
            ->set('name', 'Ramesh Fixer')->set('address', '4th Cross, Indiranagar')->set('terms', true)
            ->call('submitApplication');
    }

    public function test_a_partner_applies_with_no_password_and_no_documents_and_is_signed_straight_in(): void
    {
        $this->zone();
        $phone = $this->randomPhone();

        Livewire::test(Register::class)
            ->assertDontSeeHtml('wire:model="password"')
            ->assertDontSeeHtml('wire:model="documents.id_proof"')
            ->assertSee('No documents needed to apply');

        $this->apply($phone)->assertHasNoErrors()->assertSet('submitted', true)
            ->assertSee('Finish joining')->assertSee(route('partner.join'), false);

        $user = User::where('phone', $phone)->sole();
        $this->assertNull($user->password, 'No password: they sign in with a mobile code.');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('pending', $user->providerProfile->kyc_status);
        $this->assertSame(0, $user->providerProfile->documents()->count());
    }

    public function test_finish_joining_uploads_documents_and_the_banner_counts_what_is_missing(): void
    {
        $this->zone();
        $phone = $this->randomPhone();
        $this->apply($phone);
        $user = User::where('phone', $phone)->sole();

        $this->get(route('provider.dashboard'))->assertOk()->assertSee('upload 3 documents');

        Livewire::actingAs($user)->test(Join::class)
            ->assertSee('Government ID proof')->assertSee('Bank / settlement details')
            ->set('files.provider.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->assertSet('error', '')
            ->assertSee('Checking')
            ->set('files.provider.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('files.provider.bank_details', UploadedFile::fake()->create('bank.pdf', 100, 'application/pdf'));

        $this->assertSame(3, $user->providerProfile->documents()->where('status', 'pending')->count());
        $this->get(route('provider.dashboard'))->assertOk()->assertDontSee('data-testid="join-banner"', false);
    }

    public function test_only_documents_on_the_partners_own_list_can_be_uploaded(): void
    {
        $this->zone();
        $phone = $this->randomPhone();
        $this->apply($phone);
        $user = User::where('phone', $phone)->sole();

        Livewire::actingAs($user)->test(Join::class)
            ->set('files.provider.made_up_type', UploadedFile::fake()->image('x.jpg'))
            ->assertSet('error', 'That document is not on your list.');
        $this->assertSame(0, $user->providerProfile->documents()->count());
    }

    public function test_approval_still_needs_every_required_document(): void
    {
        $this->zone();
        $phone = $this->randomPhone();
        $this->apply($phone);
        $provider = Provider::sole();

        $this->expectExceptionMessage('required documents are not yet approved');
        app(ReviewProviderKycAction::class)->approve($provider->id);
    }

    public function test_super_admin_can_ask_for_a_password_and_documents_at_sign_up_again(): void
    {
        $this->zone();
        Setting::set(SiteControls::SIGNUP_ASK_PASSWORD, '1');
        Setting::set(SiteControls::SIGNUP_DOCS_PREFIX.'service', '1');

        $c = $this->apply($this->randomPhone())->assertHasErrors(['password'])->assertSet('submitted', false);
        $c->set('password', 'longenough1')->set('password_confirmation', 'longenough1')->call('submitApplication')
            ->assertHasErrors(['documents.id_proof'])->assertSet('submitted', false);
    }

    // ───────────────────────── sign in with a mobile code ─────────────────────────

    public function test_a_partner_signs_in_with_a_mobile_code(): void
    {
        $this->zone();
        $phone = $this->randomPhone();
        $this->apply($phone);
        $user = User::where('phone', $phone)->sole();
        auth()->logout();

        Livewire::test(PartnerLogin::class)
            ->assertSet('mode', 'code')->assertSee('Send code')
            ->set('phone', $phone)->call('requestCode')->assertDispatched('firebase-send-phone-otp')
            ->call('codeSent')->assertSet('codeStep', 'verify')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($phone)))
            ->assertRedirect(route('provider.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unknown_or_suspended_number_cannot_sign_in_with_a_code(): void
    {
        Livewire::test(PartnerLogin::class)
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($this->randomPhone())))
            ->assertSet('notAppliedYet', true)->assertNoRedirect();
        $this->assertGuest();

        $this->zone();
        $phone = $this->randomPhone();
        $this->apply($phone);
        auth()->logout();
        User::where('phone', $phone)->update(['status' => 'suspended']);
        Livewire::test(PartnerLogin::class)
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($phone)))
            ->assertSet('error', 'This account has been suspended. Contact support for help.');
        $this->assertGuest();
    }

    public function test_a_seller_signs_in_with_a_mobile_code_too(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone);
        $user = $store->provider->user;

        Livewire::test(SellerLogin::class)
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($user->phone)))
            ->assertRedirect(route('seller.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_form_is_one_tap_away(): void
    {
        $this->get(route('provider.login', ['with' => 'password']))->assertOk()->assertSee('Forgot password?');
        Livewire::test(PartnerLogin::class)->call('$set', 'mode', 'password')->assertSee('Sign in with a mobile code instead');
    }

    // ───────────────────────── header ─────────────────────────

    public function test_the_header_partner_button_counts_hotels(): void
    {
        Setting::set('hotel.recruiting_hotels', '1');
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone);
        $user = $store->provider->user; // a shop: service and shop roles taken
        \App\Models\FieldWorker::create(['user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'kyc_status' => 'approved', 'is_active' => true]);
        $user->providerProfile->update(['skills' => [1]]);

        $this->assertTrue($user->fresh()->canApplyAsPartner('hotel'));
        $this->assertTrue($user->fresh()->canAddAPartnerRole(), 'A hotel is still open to this account.');
    }
}
