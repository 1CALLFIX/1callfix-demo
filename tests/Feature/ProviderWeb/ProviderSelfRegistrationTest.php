<?php

namespace Tests\Feature\ProviderWeb;

use App\Actions\ReviewProviderKycAction;
use App\Livewire\Provider\Auth\Register;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * PHASE PSR — the public provider self-registration form. The account
 * write is the shared RegisterProviderAction (the CSV importer's is pinned
 * unchanged by ProviderPreRegisterTest); documents go through the real
 * KycDocumentService; the admin review queue and ReviewProviderKycAction
 * are exercised as-is to prove a self-registered provider is approved with
 * the same mechanism a CSV one is.
 */
class ProviderSelfRegistrationTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    /** A franchise + a zone with a real centre coordinate so nearestCoveringZone() resolves. */
    private function coveredZone(): Zone
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 12.9000000, 'center_lng' => 77.6000000]);

        return $zone->fresh();
    }

    private function verifyPhone(Testable $c, string $national): void
    {
        $token = $this->firebase->issuePhoneToken($this->e164($national));
        $c->call('requestPhoneCode')->call('phoneTokenReceived', $token)->assertSet('step', 'details');
    }

    private function attachRequiredDocs(Testable $c, array $only = ['id_proof', 'address_proof', 'bank_details']): void
    {
        $files = [
            'id_proof' => UploadedFile::fake()->image('id.jpg'),
            'address_proof' => UploadedFile::fake()->image('addr.png'),
            'bank_details' => UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'),
        ];

        foreach ($only as $type) {
            $c->set("documents.$type", $files[$type]);
        }
    }

    /** Drive the form from a verified phone through to a filled, ready-to-submit 'details' step. */
    private function readyToSubmit(string $phone, float $lat = 12.9, float $lng = 77.6): Testable
    {
        $c = Livewire::test(Register::class)->set('phone', $phone);
        $this->verifyPhone($c, $phone);

        $c->call('useCurrentLocationForNewAddress', $lat, $lng)
            ->set('name', 'Ramesh Fixer')
            ->set('password', 'longenough1')
            ->set('password_confirmation', 'longenough1')
            ->set('address', '4th Cross, Indiranagar')
            ->set('terms', true);

        return $c;
    }

    public function test_public_route_is_reachable_by_a_guest(): void
    {
        $this->get('/provider/register')->assertOk()->assertSee('Become a');
    }

    public function test_otp_step_waits_for_a_real_send_and_never_shows_code_sent_beside_an_error(): void
    {
        $c = Livewire::test(Register::class)->set('phone', $this->randomPhone());

        // requestPhoneCode holds on the phone step until the browser
        // confirms the SMS actually went out.
        $c->call('requestPhoneCode')
            ->assertSet('step', 'phone')
            ->assertSet('status', fn ($s) => str_contains($s, 'Sending'));

        // A Firebase config failure: one error, no lingering "code sent"
        // line, still on the phone step.
        $c->dispatch('firebase-error', message: 'Mobile / Google sign-in is not configured for this site yet.')
            ->assertSet('step', 'phone')
            ->assertSet('status', '')
            ->assertSet('error', 'Mobile / Google sign-in is not configured for this site yet.');

        // The real send confirmation is what advances to code entry.
        $c->call('requestPhoneCode')
            ->dispatch('firebase-phone-otp-sent')
            ->assertSet('step', 'verify_phone')
            ->assertSet('error', '')
            ->assertSet('status', fn ($s) => str_contains($s, 'Enter the code'));
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_public_form(): void
    {
        $zone = $this->coveredZone();
        $provider = $this->makeProviderIn($zone->franchise, $zone);
        $provider->user->update(['password' => Hash::make('secret-pw-1234')]);

        // The `guest` middleware bounces any authenticated visitor before
        // the component mounts — the form is never public to a session.
        $this->actingAs($provider->user->fresh())
            ->get('/provider/register')
            ->assertRedirect();
    }

    public function test_happy_path_creates_a_pending_provider_shell_with_documents_and_no_session(): void
    {
        $zone = $this->coveredZone();
        $phone = $this->randomPhone();

        $c = $this->readyToSubmit($phone);
        $c->assertSet('outOfCoverage', false)->set('email', 'ramesh@example.com');
        $this->attachRequiredDocs($c);

        $c->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $this->assertGuest();

        $user = User::where('phone', $phone)->sole();
        $this->assertSame('provider', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue(Hash::check('longenough1', $user->password));
        $this->assertSame('ramesh@example.com', $user->email);

        $provider = $user->providerProfile;
        $this->assertSame('pending', $provider->kyc_status);
        $this->assertSame('independent', $provider->provider_type);
        $this->assertSame($zone->id, $provider->zone_id);
        $this->assertSame($zone->franchise_id, $provider->franchise_id);
        $this->assertSame('4th Cross, Indiranagar', $provider->registration_address);
        $this->assertEquals(12.9, (float) $provider->registration_lat);
        $this->assertNotNull($provider->kyc_deadline_at);

        $this->assertSame(3, $provider->documents()->count());
        foreach ($provider->documents as $doc) {
            $this->assertSame('pending', $doc->status);
            $this->assertTrue((bool) $doc->is_current);
            $this->assertSame('self', $doc->upload_source);
            $this->assertSame($user->id, $doc->uploaded_by);
        }
    }

    public function test_self_registered_provider_appears_in_the_admin_pending_queue(): void
    {
        $this->coveredZone();
        $phone = $this->randomPhone();

        $c = $this->readyToSubmit($phone);
        $c->set('name', 'Queue Tester');
        $this->attachRequiredDocs($c);
        $c->call('submitApplication')->assertHasNoErrors();

        $admin = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($admin)->test(ProvidersIndex::class)
            ->assertSet('statusFilter', 'pending')
            ->assertSee('Queue Tester');
    }

    public function test_self_registered_provider_is_approvable_via_the_unchanged_review_action(): void
    {
        $this->coveredZone();
        $phone = $this->randomPhone();

        $c = $this->readyToSubmit($phone);
        $c->set('name', 'Approvable One');
        $this->attachRequiredDocs($c);
        $c->call('submitApplication')->assertHasNoErrors();

        $provider = User::where('phone', $phone)->sole()->providerProfile;

        // Reviewer approves every submitted document, then the provider.
        $provider->documents()->update(['status' => 'approved']);

        $approved = app(ReviewProviderKycAction::class)->approve($provider->id);

        // Reaching 'approved' proves the verification-video gate is waived
        // globally (D9) — otherwise approve() throws before this point.
        $this->assertSame('approved', $approved->kyc_status);
    }

    public function test_an_already_registered_phone_is_rejected_right_after_otp_never_reaching_the_details_form(): void
    {
        $phone = $this->randomPhone();

        User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Existing', 'phone' => $phone,
            'role' => 'customer', 'status' => 'active', 'password' => Hash::make('whatever12'),
        ]);

        $c = Livewire::test(Register::class)->set('phone', $phone);
        $token = $this->firebase->issuePhoneToken($this->e164($phone));

        // OTP verifies fine — but the number is already taken, so the
        // applicant is bounced straight back with "sign in instead" the
        // moment the number is proven: no details step, no uploads.
        $c->call('requestPhoneCode')
            ->dispatch('firebase-phone-otp-sent')
            ->call('phoneTokenReceived', $token)
            ->assertSee('sign in instead')
            ->assertSet('step', 'phone')
            ->assertSet('verifiedPhoneE164', '');

        $this->assertSame(0, Provider::count());
        $this->assertSame(1, User::where('phone', $phone)->count());
    }

    public function test_out_of_coverage_pin_still_registers_and_is_flagged_for_manual_placement(): void
    {
        // A covered zone exists but far away — the pin lands outside every radius.
        $this->coveredZone();
        $phone = $this->randomPhone();

        $c = $this->readyToSubmit($phone, -33.8688, 151.2093); // Sydney — nowhere near
        $c->assertSet('outOfCoverage', true)->set('name', 'Remote Applicant');
        $this->attachRequiredDocs($c);

        $c->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $provider = User::where('phone', $phone)->sole()->providerProfile;
        $this->assertSame('pending', $provider->kyc_status);
        $this->assertNotNull($provider->franchise_id); // placed on the nearest team for review
        $this->assertEquals(-33.8688, (float) $provider->registration_lat);
    }

    public function test_missing_a_required_document_blocks_submission(): void
    {
        $this->coveredZone();
        $phone = $this->randomPhone();

        $c = $this->readyToSubmit($phone);
        $c->set('name', 'No Bank');
        $this->attachRequiredDocs($c, ['id_proof', 'address_proof']); // no bank_details

        $c->call('submitApplication')
            ->assertHasErrors('documents.bank_details')
            ->assertSet('submitted', false);

        $this->assertSame(0, Provider::count());
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->coveredZone();
        $phone = $this->randomPhone();

        $c = $this->readyToSubmit($phone);
        $c->set('name', 'No Terms')->set('terms', false);
        $this->attachRequiredDocs($c);

        $c->call('submitApplication')
            ->assertHasErrors('terms')
            ->assertSet('submitted', false);
    }

    public function test_submit_is_rate_limited_per_verified_number(): void
    {
        $this->coveredZone();
        $phone = $this->randomPhone();

        $fill = function (Testable $c): void {
            $c->set('name', 'Rate Limited')
                ->set('password', 'longenough1')
                ->set('password_confirmation', 'longenough1')
                ->set('address', '4th Cross, Indiranagar')
                ->set('terms', true);
            $this->attachRequiredDocs($c);
        };

        // Phone is free at OTP time, so the fail-fast check passes and the
        // details step is reachable.
        $c = Livewire::test(Register::class)->set('phone', $phone);
        $this->verifyPhone($c, $phone);
        $c->call('useCurrentLocationForNewAddress', 12.9, 77.6);

        // Now the number gets taken — someone else registering it in the
        // window between OTP verify and submit. Every submit from here
        // clears validation, increments the submit throttle, then fails on
        // RegisterProviderAction's race-backstop check. The 4th is stopped
        // by the maxPerIdentifier=3 guard before it runs.
        User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Raced In', 'phone' => $phone,
            'role' => 'customer', 'status' => 'active', 'password' => Hash::make('whatever12'),
        ]);

        foreach (range(1, 3) as $ignored) {
            $fill($c);
            $c->call('submitApplication')
                ->assertSet('submitted', false)
                ->assertSet('error', fn ($e) => str_contains((string) $e, 'already exists'));
        }

        $fill($c);
        $c->call('submitApplication')
            ->assertSet('error', fn ($e) => str_contains((string) $e, 'Too many attempts'));
    }
}
