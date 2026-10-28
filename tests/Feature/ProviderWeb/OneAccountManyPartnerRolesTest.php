<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Livewire\SiteControls\Manage;
use App\Models\ActivityLog;
use App\Models\FieldWorker;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Owner 2026-10-27: people do not have two mobiles or two emails, so one account holds every partner role. A header
 * "Become a partner" button (Super Admin text and switch) opens the partner page for anyone, a signed-in customer
 * too; a fresh code to the account's own number is always required (owner: verification is a must for a partner);
 * a service partner can also become a rider (and the other way round) and switches dashboards with one tap.
 */
class OneAccountManyPartnerRolesTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->bindFakeFirebase();
        \App\Models\City::create(['country_id' => \App\Models\Country::create(['name' => 'Testland', 'code' => 'TL', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true])->id, 'name' => 'Nellore', 'slug' => 'nellore', 'is_active' => true]);
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4400000, 'center_lng' => 79.9800000]);
        $this->zone = $zone->fresh();
    }

    private function customer(bool $verified = true): User
    {
        $user = $this->makeCustomer();
        $user->forceFill(['name' => 'Ravi Kumar', 'password' => Hash::make('my-old-pass-1'), 'phone_verified_at' => $verified ? now() : null])->save();

        return $user->fresh();
    }

    private function recruitingRiders(): void
    {
        $this->enableParcelModuleForTests();
        $this->configureParcelPricing();
    }

    /** Signed in: the code always goes to the account's own number, then the form opens on that account. */
    private function verifyOwnNumber(Testable $c, User $user): Testable
    {
        return $c->assertSet('step', 'phone')
            ->call('requestPhoneCode')->assertDispatched('firebase-send-phone-otp', phone: $this->e164($user->phone))
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($user->phone)))
            ->assertSet('step', 'details')->assertSet('existingUserId', $user->id);
    }

    private function serviceDocs(Testable $c): Testable
    {
        return $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('address', 'Magunta Layout, Nellore')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'));
    }

    // ── Header button ───────────────────────────────────────────────────

    public function test_the_header_shows_become_a_partner_to_guests_and_customers_and_hides_it_when_switched_off(): void
    {
        $this->get(route('customer.home'))->assertOk()->assertSee('Become a partner')->assertSee(route('customer.partners'), false);
        $this->actingAs($this->customer())->get(route('customer.home'))->assertOk()->assertSee('Become a partner');

        Setting::set(SiteControls::HEADER_PARTNER_LABEL, 'Join us');
        $this->get(route('customer.home'))->assertSee('Join us');

        Setting::set(SiteControls::HEADER_PARTNER_BUTTON, '0');
        $this->get(route('customer.home'))->assertDontSee('Join us');
    }

    public function test_the_header_button_hides_once_the_account_holds_every_partner_role_and_for_staff(): void
    {
        $provider = $this->makeProviderIn($this->zone->franchise, $this->zone);
        $this->actingAs($provider->user)->get(route('customer.home'))->assertSee('Become a partner');

        FieldWorker::create(['user_id' => $provider->user_id, 'franchise_id' => $this->zone->franchise_id, 'zone_id' => $this->zone->id, 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false]);
        $this->actingAs($provider->user->fresh())->get(route('customer.home'))->assertDontSee('Become a partner');

        $this->actingAs($this->makeSuperAdmin())->get(route('customer.home'))->assertDontSee('Become a partner');
    }

    public function test_super_admin_sets_the_button_text_and_switch_with_an_audit_trail(): void
    {
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSet('headerPartnerButton', true)->assertSet('headerPartnerLabel', 'Become a partner')
            ->set('headerPartnerLabel', '')->call('save')->assertHasErrors('headerPartnerLabel')
            ->set('headerPartnerLabel', 'Work with us')->set('headerPartnerButton', false)->call('save')->assertHasNoErrors();

        $this->assertSame('Work with us', SiteControls::headerPartnerLabel());
        $this->assertSame('0', Setting::get(SiteControls::HEADER_PARTNER_BUTTON));
        $this->assertTrue(ActivityLog::where('description', 'Setting '.SiteControls::HEADER_PARTNER_LABEL.' changed')->exists());
    }

    // ── Signed-in customer ──────────────────────────────────────────────

    public function test_the_partner_page_form_starts_from_the_signed_in_account_and_takes_a_role_from_the_link(): void
    {
        $this->recruitingRiders();
        $customer = $this->customer();

        Livewire::actingAs($customer)->withQueryParams(['role' => 'parcel'])->test(ApplyForm::class)
            ->assertSet('name', 'Ravi Kumar')->assertSet('phone', $customer->phone)->assertSet('role', 'parcel');
        Livewire::withQueryParams(['role' => 'nonsense'])->test(ApplyForm::class)->assertSet('role', 'service');
    }

    public function test_a_signed_in_customer_still_verifies_a_fresh_code_even_with_a_verified_mobile(): void
    {
        $customer = $this->customer(verified: true);
        $this->actingAs($customer)->get(route('provider.register'))->assertOk();

        $c = Livewire::actingAs($customer)->test(Register::class)
            ->assertSet('verifiedPhoneE164', '')
            ->assertSet('phone', $customer->phone);
        // No code yet → cannot submit.
        $this->serviceDocs($c)->call('submitApplication')->assertSet('submitted', false)
            ->assertSet('error', 'Verify your mobile number first.');

        $this->verifyOwnNumber($c, $customer)->assertDontSeeHtml('wire:model="password"');

        $this->serviceDocs($c)->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $this->assertSame(1, User::where('phone', $customer->phone)->count());
        $this->assertSame('pending', Provider::where('user_id', $customer->id)->sole()->fresh()->kyc_status);
        $this->assertTrue(Hash::check('my-old-pass-1', $customer->fresh()->password));
        $this->assertAuthenticatedAs($customer);
    }

    public function test_a_signed_in_customer_without_a_verified_mobile_gets_one_code_on_their_own_number_only(): void
    {
        $customer = $this->customer(verified: false);

        $c = Livewire::actingAs($customer)->test(Register::class)
            ->assertSet('step', 'phone')->assertSet('phone', $customer->phone)
            ->set('phone', '9000000001')->call('requestPhoneCode')
            ->assertSet('phone', $customer->phone)
            ->assertDispatched('firebase-send-phone-otp', phone: $this->e164($customer->phone));

        // A code for some other number is refused: the application is for the signed-in account.
        $c->call('phoneTokenReceived', $this->firebase->issuePhoneToken('+919000000001'))
            ->assertSet('verifiedPhoneE164', '')
            ->assertSee('account you are signed in with');

        $c->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($customer->phone)))
            ->assertSet('step', 'details')->assertSet('existingUserId', $customer->id);
        $this->serviceDocs($c)->call('submitApplication')->assertSet('submitted', true);

        $this->assertNotNull($customer->fresh()->phone_verified_at);
        $this->assertSame(1, Provider::where('user_id', $customer->id)->count());
    }

    public function test_a_signed_in_partner_opening_the_same_role_sign_up_goes_to_their_dashboard(): void
    {
        $provider = $this->makeProviderIn($this->zone->franchise, $this->zone);

        Livewire::actingAs($provider->user)->test(Register::class)->assertRedirect(route('provider.dashboard'));
        $this->assertSame(1, Provider::count());
    }

    // ── Several roles on one account ───────────────────────────────────

    public function test_a_service_partner_also_becomes_a_rider_on_the_same_account_and_switches_with_one_tap(): void
    {
        $this->recruitingRiders();
        $provider = $this->makeProviderIn($this->zone->franchise, $this->zone);
        $user = $provider->user;
        $user->forceFill(['phone_verified_at' => now(), 'password' => Hash::make('my-old-pass-1')])->save();

        // The partner dashboard offers "Deliver parcels too" while riders are being recruited.
        $this->actingAs($user)->get(route('provider.dashboard'))->assertOk()
            ->assertSee('Deliver parcels too')->assertSee(route('customer.partners', ['role' => 'parcel']), false);

        // /partners (rider card) → the rider sign-up; a fresh code to the account's own number.
        Livewire::actingAs($user)->test(ApplyForm::class)
            ->set('role', 'parcel')->set('cityChoice', 'nellore')->set('consent', true)->call('submit')
            ->assertSet('outcome', 'handoff');

        $c = Livewire::actingAs($user)->test(Register::class)->assertSet('partnerRole', 'parcel');
        $this->verifyOwnNumber($c, $user);
        $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('address', 'Magunta Layout, Nellore')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->set('documents.driving_licence', UploadedFile::fake()->image('dl.jpg'))
            ->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $rider = FieldWorker::where('user_id', $user->id)->sole();
        $this->assertSame('pending', $rider->kyc_status);
        $this->assertTrue($rider->hasCapability('parcel_rider'));
        $this->assertSame(1, Provider::where('user_id', $user->id)->count(), 'still the same service partner');
        $this->assertSame(1, User::where('phone', $user->phone)->count());

        // Both dashboards, one sign-in: each offers a switch to the other.
        $this->actingAs($user->fresh())->get(route('provider.dashboard'))->assertOk()->assertSee('Switch to rider mode');
        $this->actingAs($user->fresh())->get(route('rider.dashboard'))->assertOk()->assertSee('Switch to partner mode');
    }

    public function test_a_rider_can_add_services_and_a_second_same_role_application_is_refused(): void
    {
        $rider = $this->makeParcelRiderIn($this->zone->franchise, $this->zone);
        $user = $rider->user;
        $user->forceFill(['phone_verified_at' => now()])->save();

        $this->assertTrue($user->fresh()->canApplyAsPartner('service'));
        $this->assertFalse($user->fresh()->canApplyAsPartner('parcel'));

        $c = $this->verifyOwnNumber(Livewire::actingAs($user->fresh())->test(Register::class), $user);
        $this->serviceDocs($c)->call('submitApplication')->assertSet('submitted', true);
        $this->assertSame(1, Provider::where('user_id', $user->id)->count());

        $this->assertFalse($user->fresh()->canApplyAsPartner('service'));
        $this->assertFalse($user->fresh()->canAddAPartnerRole());
    }

    public function test_staff_and_suspended_accounts_never_apply_from_a_signed_in_session(): void
    {
        $staff = $this->customer();
        $staff->forceFill(['role' => 'support'])->save();
        Livewire::actingAs($staff->fresh())->test(Register::class)->assertRedirect(route('customer.home'));

        $suspended = $this->customer();
        $this->assertFalse(tap($suspended)->forceFill(['status' => 'suspended'])->canApplyAsPartner('service'));
        $this->assertSame(0, Provider::count());
    }
}
