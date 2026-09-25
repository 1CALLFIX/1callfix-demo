<?php

namespace Tests\Feature\Referral;

use App\Livewire\Customer\Auth\GoogleAuth;
use App\Livewire\Customer\Auth\Signup;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\Onboarding\CustomerPreRegisterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\EarningsSettingsFixtureValues;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 2: referral capture at signup
 * (R1–R5, R9).
 */
class ReferralCaptureTest extends TestCase
{
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        Notification::fake();
        EarningsSettingsFixtureValues::referral(); // program ON, cap 1000
    }

    private function referrer(array $overrides = []): User
    {
        $u = $this->passwordCustomer('secret1234');
        $u->forceFill($overrides)->save();

        return $u->fresh();
    }

    // ───────────────────────── web ─────────────────────────

    private function webSignup(string $phone, ?string $ref = null, ?string $typed = null)
    {
        $c = $ref !== null
            ? Livewire::withQueryParams(['ref' => $ref])->test(Signup::class)
            : Livewire::test(Signup::class);
        $c->set('phone', $phone);
        if ($typed !== null) {
            $c->set('referralCode', $typed);
        }
        $token = $this->firebase->issuePhoneToken($this->e164($phone));
        $c->call('requestPhoneCode')->call('phoneTokenReceived', $token);

        return $c->set('name', 'New Person')->set('password', 'longenough1')->set('password_confirmation', 'longenough1');
    }

    public function test_web_ref_prefills_the_field_and_a_valid_code_creates_a_pending_referral(): void
    {
        $referrer = $this->referrer();
        $phone = $this->randomPhone();

        $this->webSignup($phone, $referrer->referral_code)
            ->assertSet('referralCode', $referrer->referral_code)
            ->assertSet('referralError', '')
            ->call('completeSignup')
            ->assertRedirect(route('customer.home'));

        $user = User::where('phone', $phone)->sole();
        $this->assertSame($referrer->id, $user->referred_by);
        $referral = Referral::where('referred_id', $user->id)->sole();
        $this->assertSame('pending', $referral->status);
        $this->assertSame($referrer->id, $referral->referrer_id);
    }

    public function test_the_web_field_is_rendered_with_the_code(): void
    {
        $referrer = $this->referrer();

        $this->webSignup($this->randomPhone(), $referrer->referral_code)
            ->assertSet('step', 'details')
            ->assertSet('referralCode', $referrer->referral_code)
            ->assertSee('Referral code (optional)');
    }

    /** @return array<string, array{0: string}> */
    public static function invalidCases(): array
    {
        return ['unknown' => ['unknown'], 'self' => ['self'], 'blocked' => ['blocked'], 'inactive' => ['inactive']];
    }

    private function invalidCode(string $case, string $refereePhone): string
    {
        return match ($case) {
            'unknown' => 'NOPE999',
            'self' => $this->referrer(['phone' => $refereePhone])->referral_code,
            'blocked' => $this->referrer(['referral_blocked_at' => now(), 'referral_blocked_reason' => 'abuse'])->referral_code,
            'inactive' => $this->referrer(['status' => 'suspended'])->referral_code,
        };
    }

    #[DataProvider('invalidCases')]
    public function test_web_invalid_code_shows_inline_message_and_never_blocks_signup(string $case): void
    {
        $phone = $this->randomPhone();
        // "self": the code's owner has the signer's own phone (a password-less
        // shell, so the signup itself can still complete by resuming it).
        $code = $case === 'self'
            ? $this->referrer(['phone' => $phone, 'password' => null])->referral_code
            : $this->invalidCode($case, $phone);

        $this->webSignup($phone, null, $code)
            ->assertSet('referralError', 'This referral code is not valid.')
            ->call('completeSignup')
            ->assertRedirect(route('customer.home'));

        $this->assertSame(0, Referral::count(), 'no referral row');
        $this->assertNull(User::where('phone', $phone)->sole()->referred_by);
    }

    // ───────────────────────── API ─────────────────────────

    public function test_api_register_with_a_valid_code(): void
    {
        $referrer = $this->referrer();
        $phone = $this->randomPhone();

        $this->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)),
            'name' => 'Api Person', 'password' => 'longenough1', 'referral_code' => $referrer->referral_code,
        ])->assertCreated();

        $user = User::where('phone', $phone)->sole();
        $this->assertSame($referrer->id, $user->referred_by);
        $this->assertSame('pending', Referral::where('referred_id', $user->id)->value('status'));
    }

    public function test_api_firebase_phone_signup_with_a_valid_code(): void
    {
        $referrer = $this->referrer();
        $phone = $this->randomPhone();

        $this->postJson('/api/auth/firebase', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)), 'actor_type' => 'customer',
            'name' => 'Fb Person', 'password' => 'longenough1', 'referral_code' => strtolower($referrer->referral_code),
        ])->assertOk();

        $this->assertSame($referrer->id, User::where('phone', $phone)->sole()->referred_by);
        $this->assertSame(1, Referral::count());
    }

    public function test_api_google_signup_with_a_valid_code(): void
    {
        $referrer = $this->referrer();
        $phone = $this->randomPhone();

        $this->postJson('/api/auth/firebase', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)),
            'google_id_token' => $this->firebase->issueGoogleToken('g.ref@example.com', 'G Ref', 'guid-ref'),
            'actor_type' => 'customer', 'referral_code' => $referrer->referral_code,
        ])->assertOk();

        $this->assertSame($referrer->id, User::where('email', 'g.ref@example.com')->sole()->referred_by);
        $this->assertSame(1, Referral::count());
    }

    #[DataProvider('invalidCases')]
    public function test_api_invalid_code_is_a_field_level_validation_error(string $case): void
    {
        $phone = $this->randomPhone();
        $code = $case === 'self' ? $this->referrer(['phone' => $phone, 'password' => null])->referral_code : $this->invalidCode($case, $phone);

        $this->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)),
            'name' => 'Api Person', 'password' => 'longenough1', 'referral_code' => $code,
        ])->assertStatus(422)->assertJsonValidationErrors(['referral_code' => 'This referral code is not valid.']);

        $this->assertSame(0, Referral::count());
    }

    public function test_web_google_signup_uses_the_code_from_the_signup_link(): void
    {
        $referrer = $this->referrer();
        Livewire::withQueryParams(['ref' => $referrer->referral_code])->test(Signup::class); // stores it in the session

        session(['auth.google' => ['uid' => 'guid-web-ref', 'email' => 'web.g@example.com', 'name' => 'Web G',
            'email_verified' => true, 'verified_at' => now()->timestamp]]);
        $phone = $this->randomPhone();

        Livewire::test(GoogleAuth::class)
            ->set('phone', $phone)
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($phone)))
            ->assertRedirect(route('customer.home'));

        $this->assertSame($referrer->id, User::where('email', 'web.g@example.com')->sole()->referred_by);
    }

    // ───────────────────────── cap, switch, R1 ─────────────────────────

    public function test_referrer_at_cap_is_accepted_but_records_why_no_reward(): void
    {
        Setting::set('referral.max_per_customer', '1');
        $referrer = $this->referrer();
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->passwordCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 50]);
        $phone = $this->randomPhone();

        $this->webSignup($phone, null, $referrer->referral_code)->call('completeSignup');

        $referral = Referral::where('referred_id', User::where('phone', $phone)->value('id'))->sole();
        $this->assertSame('pending', $referral->status);
        $this->assertSame('referrer_cap_reached', $referral->no_reward_reason);
    }

    #[DataProvider('offStates')]
    public function test_program_off_hides_the_field_ignores_the_code_and_creates_no_row(?string $state): void
    {
        $referrer = $this->referrer();
        $state === null ? Setting::clear('referral.enabled', 'global', null) : Setting::set('referral.enabled', $state);
        $phone = $this->randomPhone();

        $this->webSignup($this->randomPhone(), $referrer->referral_code)
            ->assertSet('step', 'details')
            ->assertSet('referralCode', '')
            ->assertDontSee('Referral code (optional)');

        $this->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)),
            'name' => 'Off Person', 'password' => 'longenough1', 'referral_code' => $referrer->referral_code,
        ])->assertCreated();

        $this->assertNull(User::where('phone', $phone)->sole()->referred_by);
        $this->assertSame(0, Referral::count());
    }

    /** @return array<string, array{0: ?string}> */
    public static function offStates(): array
    {
        return ['explicit off' => ['0'], 'unset' => [null]];
    }

    public function test_admin_quick_create_and_importer_never_capture_a_code(): void
    {
        $referrer = $this->referrer();

        $importer = new CustomerPreRegisterImporter;
        $phone = $this->randomPhone();
        $rows = collect([collect(['name' => 'Imported', 'phone' => $phone, 'referral_code' => $referrer->referral_code, 'referred_by' => $referrer->id])]);
        $importer->commit($importer->validateRows($rows)['previewRows'], null, 'c.csv');

        $this->assertNull(User::where('phone', $phone)->sole()->referred_by);

        // Admin quick-create (Livewire\Bookings\Index::createCustomer) builds
        // its User::create array from name + phone only; mass assignment can't
        // carry referral fields either way now that they aren't fillable.
        $u = User::create(['uuid' => (string) Str::uuid(), 'name' => 'X', 'phone' => $this->randomPhone(), 'role' => 'customer',
            'status' => 'active', 'referred_by' => $referrer->id, 'referral_code' => 'HACK1']);
        $this->assertNull($u->fresh()->referred_by);
        $this->assertNotSame('HACK1', $u->fresh()->referral_code);
        $this->assertSame(0, Referral::count());
    }

    public function test_a_code_cannot_be_attached_after_signup_by_any_route(): void
    {
        $referrer = $this->referrer();
        $customer = $this->passwordCustomer();

        // profile update ignores it
        $this->actingAs($customer, 'sanctum')->putJson('/api/profile', ['name' => 'Renamed', 'referred_by' => $referrer->id, 'referral_code' => 'HACK2']);
        // a later login with a code ignores it
        $this->postJson('/api/auth/firebase', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($customer->phone)), 'actor_type' => 'customer',
            'referral_code' => $referrer->referral_code,
        ])->assertOk();
        // resuming a password-less shell is not "creation" — the code is ignored
        $shell = $this->legacyPasswordlessCustomer();
        $this->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($shell->phone)),
            'name' => 'Shell', 'password' => 'longenough1', 'referral_code' => $referrer->referral_code,
        ])->assertCreated();

        $this->assertNull($customer->fresh()->referred_by);
        $this->assertNull($shell->fresh()->referred_by);
        $this->assertSame(0, Referral::count());

        // No route is registered that takes a referral code other than signup.
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_contains($u, 'referral'))->values()->all();
        $this->assertSame([], array_values(array_filter($routes, fn ($u) => ! str_contains($u, 'referrals/mine') && ! str_contains($u, 'earnings/referrals'))));
    }

    // ───────────────────────── R5 holds ─────────────────────────

    private function apiSignup(string $ip, ?string $device, string $code): User
    {
        $phone = $this->randomPhone();
        $headers = $device ? ['X-Device-Id' => $device] : [];
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)),
            'name' => 'Held Person', 'password' => 'longenough1', 'referral_code' => $code,
        ], $headers)->assertCreated();

        return User::where('phone', $phone)->sole();
    }

    public function test_same_ip_holds_for_review_with_the_setting_unset(): void
    {
        $referrer = $this->referrer(['last_login_ip' => '10.0.0.7']);

        $user = $this->apiSignup('10.0.0.7', null, $referrer->referral_code);

        $referral = Referral::where('referred_id', $user->id)->sole();
        $this->assertSame('held_review', $referral->status);
        $this->assertSame('same_signup_ip', $referral->hold_reason);
        $this->assertSame('10.0.0.7', $referral->signup_ip);
        $this->assertSame('10.0.0.7', $user->signup_ip);
    }

    public function test_same_device_holds_for_review_with_the_setting_unset(): void
    {
        $referrer = $this->referrer(['signup_device_id' => 'dev-abc']);

        $user = $this->apiSignup('10.0.0.8', 'dev-abc', $referrer->referral_code);

        $referral = Referral::where('referred_id', $user->id)->sole();
        $this->assertSame('held_review', $referral->status);
        $this->assertSame('same_device', $referral->hold_reason);
        $this->assertSame('dev-abc', $referral->signup_device_id);
    }

    public function test_each_hold_switched_off_explicitly_gives_a_normal_pending_referral(): void
    {
        Setting::set('referral.hold_same_signup_ip', '0');
        Setting::set('referral.hold_same_device', '0');
        $referrer = $this->referrer(['last_login_ip' => '10.0.0.9', 'last_login_device_id' => 'dev-x']);

        $user = $this->apiSignup('10.0.0.9', 'dev-x', $referrer->referral_code);

        $referral = Referral::where('referred_id', $user->id)->sole();
        $this->assertSame('pending', $referral->status);
        $this->assertNull($referral->hold_reason);
    }

    public function test_only_the_ip_hold_off_still_holds_on_device(): void
    {
        Setting::set('referral.hold_same_signup_ip', '0');
        $referrer = $this->referrer(['last_login_ip' => '10.0.0.10', 'last_login_device_id' => 'dev-y']);

        $user = $this->apiSignup('10.0.0.10', 'dev-y', $referrer->referral_code);

        $this->assertSame('same_device', Referral::where('referred_id', $user->id)->value('hold_reason'));
    }

    public function test_different_ip_and_device_is_pending(): void
    {
        $referrer = $this->referrer(['last_login_ip' => '10.0.0.11', 'last_login_device_id' => 'dev-z']);

        $user = $this->apiSignup('10.0.0.12', 'dev-other', $referrer->referral_code);

        $this->assertSame('pending', Referral::where('referred_id', $user->id)->value('status'));
    }

    public function test_logins_record_the_referrers_ip_and_device(): void
    {
        $customer = $this->passwordCustomer('secret1234');

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])->postJson('/api/auth/password', [
            'identifier' => $customer->phone, 'password' => 'secret1234', 'actor_type' => 'customer',
        ], ['X-Device-Id' => 'dev-login'])->assertOk();

        $fresh = $customer->fresh();
        $this->assertSame('10.1.1.1', $fresh->last_login_ip);
        $this->assertSame('dev-login', $fresh->last_login_device_id);
        $this->assertNotNull($fresh->last_login_at);
    }

    public function test_held_referral_never_rewards_on_booking_completion(): void
    {
        $referrer = $this->referrer(['last_login_ip' => '10.0.0.20']);
        $user = $this->apiSignup('10.0.0.20', null, $referrer->referral_code);
        $booking = (new \App\Models\Booking)->forceFill(['id' => 999999, 'customer_id' => $user->id, 'status' => 'completed']);

        $this->assertNull(app(\App\Services\ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('held_review', Referral::where('referred_id', $user->id)->value('status'));
    }

    // ───────────────────────── personal data ─────────────────────────

    public function test_signup_ip_and_device_are_never_serialised(): void
    {
        $referrer = $this->referrer(['last_login_ip' => '10.0.0.30']);
        $user = $this->apiSignup('10.0.0.30', 'dev-pii', $referrer->referral_code);

        $json = Referral::where('referred_id', $user->id)->sole()->toArray();
        $this->assertArrayNotHasKey('signup_ip', $json);
        $this->assertArrayNotHasKey('signup_device_id', $json);
    }
}
