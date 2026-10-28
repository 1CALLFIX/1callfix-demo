<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Customer\Auth\ForgotPassword;
use App\Livewire\Provider\Auth\Login as ProviderLogin;
use App\Livewire\Provider\Auth\Register;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use App\Services\AudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * D10 (owner 2026-10-27): a number that already has a customer account could not become a partner — sign-up said
 * "already exists, sign in" and partner sign-in said "not a partner". Now the OTP-proven customer applies on the same
 * account: a pending provider profile is added, the password, role and bookings stay. Partner and rider sign-in also
 * get "Forgot password?", which returns them to their own sign-in page.
 */
class ExistingCustomerBecomesPartnerTest extends TestCase
{
    use BookingFixtureHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 12.9000000, 'center_lng' => 77.6000000]);
        $this->zone = $zone->fresh();
    }

    private function customer(string $status = 'active'): User
    {
        $user = $this->makeCustomer();
        $user->forceFill(['name' => 'Ravi Kumar', 'password' => Hash::make('my-old-pass-1'), 'status' => $status])->save();

        return $user->fresh();
    }

    private function verified(string $national): Testable
    {
        $c = Livewire::test(Register::class)->set('phone', $national);
        $c->call('requestPhoneCode')->assertHasNoErrors()
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($national)));

        return $c;
    }

    private function fillAndSubmit(Testable $c): Testable
    {
        return $c->call('useCurrentLocationForNewAddress', 12.9, 77.6)
            ->set('address', '4th Cross, Indiranagar')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication');
    }

    public function test_an_existing_customer_is_not_refused_and_applies_on_the_same_account(): void
    {
        $customer = $this->customer();

        $c = $this->verified($customer->phone)
            ->assertSet('step', 'details')
            ->assertSet('existingUserId', $customer->id)
            ->assertSee('Applying with your')
            ->assertSee('Ravi Kumar')
            ->assertDontSeeHtml('wire:model="password"');

        $this->fillAndSubmit($c)->assertHasNoErrors()->assertSet('submitted', true);

        $this->assertSame(1, User::where('phone', $customer->phone)->count(), 'no second account');
        $provider = Provider::where('user_id', $customer->id)->sole();
        $this->assertSame('pending', $provider->fresh()->kyc_status);
        $this->assertSame($this->zone->id, $provider->zone_id);
        $this->assertSame(3, $provider->documents()->where('status', 'pending')->count());

        $fresh = $customer->fresh();
        $this->assertSame('customer', $fresh->role, 'customer features keep working');
        $this->assertSame('Ravi Kumar', $fresh->name);
        $this->assertTrue(Hash::check('my-old-pass-1', $fresh->password), 'password unchanged');
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertGuest();
    }

    public function test_after_applying_the_customer_signs_in_to_the_partner_area_with_the_old_password(): void
    {
        $customer = $this->customer();
        $this->fillAndSubmit($this->verified($customer->phone))->assertSet('submitted', true);

        Livewire::test(ProviderLogin::class)
            ->set('identifier', $customer->phone)->set('password', 'my-old-pass-1')
            ->call('login')->assertSet('error', '')->assertRedirect(route('provider.dashboard'));
    }

    public function test_the_new_partner_receives_partner_broadcasts(): void
    {
        $customer = $this->customer();
        $this->fillAndSubmit($this->verified($customer->phone))->assertSet('submitted', true);

        $ids = app(AudienceResolver::class)->resolve(['recipient_type' => 'providers', 'scope_type' => 'global', 'scope_id' => null])->pluck('id');
        $this->assertContains($customer->id, $ids->all());
    }

    public function test_a_number_that_is_already_a_partner_or_suspended_is_still_told_to_sign_in(): void
    {
        $partner = $this->makeProviderIn($this->zone->franchise, $this->zone)->user;
        Livewire::test(Register::class)->set('phone', $partner->phone)->call('requestPhoneCode')
            ->assertHasErrors('phone')->assertSee('sign in instead');

        $suspended = $this->customer('suspended');
        Livewire::test(Register::class)->set('phone', $suspended->phone)->call('requestPhoneCode')
            ->assertHasErrors('phone')->assertSee('sign in instead');

        $this->assertSame(1, Provider::count());
    }

    public function test_a_customer_suspended_after_the_otp_cannot_finish(): void
    {
        $customer = $this->customer();
        $c = $this->verified($customer->phone)->assertSet('existingUserId', $customer->id);
        $customer->update(['status' => 'suspended']);

        $this->fillAndSubmit($c)->assertSet('submitted', false)
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'already exists'));
        $this->assertSame(0, Provider::count());
    }

    public function test_a_new_number_still_needs_a_name_and_password(): void
    {
        $c = $this->verified($this->randomPhone())->assertSet('existingUserId', null)
            ->assertSeeHtml('wire:model="password"');

        $this->fillAndSubmit($c)->assertHasErrors(['name', 'password'])->assertSet('submitted', false);
    }

    public function test_partner_and_rider_sign_in_link_to_forgot_password_which_returns_them_there(): void
    {
        $this->get(route('provider.login'))->assertOk()
            ->assertSee('Forgot password?')->assertSee(route('customer.password.forgot', ['for' => 'partner']), false);
        $this->get(route('rider.login'))->assertOk()
            ->assertSee(route('customer.password.forgot', ['for' => 'rider']), false);

        Livewire::withQueryParams(['for' => 'partner'])->test(ForgotPassword::class)->assertSet('signInRoute', 'provider.login');
        Livewire::withQueryParams(['for' => 'rider'])->test(ForgotPassword::class)->assertSet('signInRoute', 'rider.login');
        Livewire::withQueryParams(['for' => 'elsewhere'])->test(ForgotPassword::class)->assertSet('signInRoute', 'customer.login');
    }

    public function test_admin_sign_in_links_to_forgot_password_and_staff_reset_returns_there(): void
    {
        $this->get(route('admin.login'))->assertOk()
            ->assertSee('Forgot password?')->assertSee(route('customer.password.forgot', ['for' => 'admin']), false);

        $staff = $this->customer();
        $staff->forceFill(['role' => 'franchise_owner'])->save();

        Livewire::withQueryParams(['for' => 'admin'])->test(ForgotPassword::class)
            ->assertSee(route('admin.login'), false)
            ->set('identifier', $staff->phone)->call('submitIdentifier')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($staff->phone)))
            ->assertSet('step', 'new_password')
            ->set('password', 'brand-new-pass-2')->set('password_confirmation', 'brand-new-pass-2')
            ->call('setNewPassword')->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('brand-new-pass-2', $staff->fresh()->password));
    }

    public function test_a_customer_with_the_right_password_on_partner_sign_in_is_sent_to_finish_the_application(): void
    {
        $customer = $this->customer();

        Livewire::test(ProviderLogin::class)
            ->set('identifier', $customer->phone)->set('password', 'my-old-pass-1')->call('login')
            ->assertSet('notAppliedYet', true)
            ->assertSee('no partner application yet')
            ->assertSee(route('provider.register'), false)
            ->assertNoRedirect();
        $this->assertGuest();

        // Then the application itself, on the same account, and sign-in works.
        $this->fillAndSubmit($this->verified($customer->phone))->assertSet('submitted', true);
        Livewire::test(ProviderLogin::class)
            ->set('identifier', $customer->phone)->set('password', 'my-old-pass-1')->call('login')
            ->assertRedirect(route('provider.dashboard'));
    }

    public function test_the_reset_page_says_code_sent_only_after_firebase_confirms_and_never_beside_an_error(): void
    {
        $customer = $this->customer();

        $c = Livewire::test(ForgotPassword::class)->set('identifier', $customer->phone)->call('submitIdentifier')
            ->assertSet('step', 'verify_phone')
            ->assertDontSee('Enter the code sent to')
            ->assertSee('Sending a code to');

        $c->dispatch('firebase-phone-otp-sent')->assertSee('Enter the code sent to');

        $c->dispatch('firebase-error', message: 'Too many attempts from this device. Try again later.')
            ->assertSet('status', '')
            ->assertSee('Too many attempts');
    }

    public function test_a_super_admin_password_is_never_reset_by_otp_on_web_or_api(): void
    {
        $admin = $this->customer();
        $admin->forceFill(['role' => 'super_admin'])->save();
        $token = fn () => $this->firebase->issuePhoneToken($this->e164($admin->phone));

        Livewire::withQueryParams(['for' => 'admin'])->test(ForgotPassword::class)
            ->set('identifier', $admin->phone)->call('submitIdentifier')
            ->call('phoneTokenReceived', $token())
            ->assertSet('step', 'verify_phone')
            ->assertSet('error', ForgotPassword::SUPER_ADMIN_BLOCKED);

        $this->postJson('/api/auth/password/reset', [
            'identifier' => $admin->phone, 'id_token' => $token(), 'new_password' => 'hijacked-pass-9',
        ])->assertStatus(403);

        $this->assertTrue(Hash::check('my-old-pass-1', $admin->fresh()->password), 'password unchanged');
    }
}
