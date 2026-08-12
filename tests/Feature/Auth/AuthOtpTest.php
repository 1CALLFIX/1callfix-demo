<?php

namespace Tests\Feature\Auth;

use App\Contracts\SmsAdapter;
use App\Models\FieldWorker;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Real HTTP tests for the login OTP foundation (Part 22/23 of the
 * authentication mission). Uses a bound test-only SmsAdapter (Part 17) to
 * capture the real code the real OtpService generates and sends — never a
 * mock of the verification logic itself, only of the external send.
 */
class AuthOtpTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private CapturingSmsAdapter $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $this->sms);
    }

    private function requestAndCaptureCode(string $phone, string $actorType): string
    {
        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'actor_type' => $actorType])->assertOk();
        $code = $this->sms->lastCodeTo($phone);
        $this->assertNotNull($code, 'Expected an OTP to have been sent to '.$phone);

        return $code;
    }

    // ============================= Customer login =============================

    public function test_customer_can_request_and_verify_otp_creating_a_new_account(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');

        $code = $this->requestAndCaptureCode($phone, 'customer');

        $response = $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $code]);

        $response->assertOk()->assertJsonPath('actor_type', 'customer');
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', ['phone' => $phone, 'role' => 'customer']);
    }

    public function test_customer_login_reuses_existing_account_by_phone(): void
    {
        $customer = $this->makeCustomer();
        $code = $this->requestAndCaptureCode($customer->phone, 'customer');

        $response = $this->postJson('/api/auth/otp/verify', ['phone' => $customer->phone, 'actor_type' => 'customer', 'code' => $code]);

        $response->assertOk()->assertJsonPath('user.id', $customer->id);
        $this->assertSame(1, User::where('phone', $customer->phone)->count(), 'Must not create a duplicate user for an existing phone.');
    }

    public function test_wrong_otp_is_rejected_and_allows_retry(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $this->requestAndCaptureCode($phone, 'customer');

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Incorrect code.']);

        // The booking itself is untouched by this concept, but the
        // equivalent guarantee here is: a wrong code must not consume or
        // invalidate the pending OTP — a retry with the real code still works.
        $realCode = $this->sms->lastCodeTo($phone);
        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $realCode])
            ->assertOk();
    }

    public function test_repeated_wrong_attempts_lock_after_max(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $this->requestAndCaptureCode($phone, 'customer');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => '000000']);
        }

        $realCode = $this->sms->lastCodeTo($phone);
        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $realCode])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many incorrect attempts. Request a new code.']);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $this->requestAndCaptureCode($phone, 'customer');
        Otp::where('phone', $phone)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => '111111'])
            ->assertStatus(410)
            ->assertJson(['message' => 'This code has expired. Request a new one.']);
    }

    public function test_verifying_an_already_verified_code_fails(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $code = $this->requestAndCaptureCode($phone, 'customer');

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $code])->assertOk();

        // Replay of the exact same, already-consumed code.
        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $code])
            ->assertStatus(410);
    }

    public function test_resend_before_cooldown_is_rejected(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'actor_type' => 'customer'])->assertOk();

        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'actor_type' => 'customer'])
            ->assertStatus(429);
    }

    public function test_a_new_request_invalidates_the_previous_pending_code(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $firstCode = $this->requestAndCaptureCode($phone, 'customer');

        \App\Models\Setting::set('auth.otp_resend_cooldown_seconds', '0');
        $secondCode = $this->requestAndCaptureCode($phone, 'customer');
        $this->assertNotSame($firstCode, $secondCode);

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $firstCode])
            ->assertStatus(422); // the old code is no longer the pending one -> treated as incorrect, not silently accepted
    }

    // ============================= Provider/Worker login =============================

    public function test_provider_login_fails_when_no_provider_account_exists(): void
    {
        // A phone with genuinely no provider account never receives an OTP
        // to submit at all (see the enumeration-safety test below) — the
        // 404 "verified but no matching profile" branch is only reachable
        // with a REAL, correctly-verified code that resolves to no
        // provider profile. purpose='login' isn't actor_type-scoped (proving
        // phone ownership is the security boundary; which profile the
        // caller is routed to is a separate, later check) — request as
        // customer (which always sends), then verify claiming provider.
        $phone = '9'.fake()->unique()->numerify('#########');
        $code = $this->requestAndCaptureCode($phone, 'customer');

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'provider', 'code' => $code])
            ->assertStatus(404);
    }

    public function test_requesting_otp_for_a_phone_with_no_provider_account_sends_nothing(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');

        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'actor_type' => 'provider'])->assertOk();

        $this->assertNull($this->sms->lastCodeTo($phone), 'No account, no eligible profile -- must not actually send an OTP.');
        $this->assertDatabaseMissing('otps', ['phone' => $phone]);
    }

    public function test_otp_request_gives_identical_response_whether_or_not_a_provider_account_exists(): void
    {
        // Enumeration safety (Part 21) — verified at the HTTP response
        // level, not just by reading the code.
        $existingPhone = '9'.fake()->unique()->numerify('#########');
        [, $franchise, $zone] = array_values($this->makeFranchiseTree());
        $providerUser = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => $existingPhone, 'role' => 'provider', 'status' => 'active']);
        \App\Models\Provider::create(['user_id' => $providerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true]);

        $nonexistentPhone = '9'.fake()->unique()->numerify('#########');

        $r1 = $this->postJson('/api/auth/otp/request', ['phone' => $existingPhone, 'actor_type' => 'provider']);
        $r2 = $this->postJson('/api/auth/otp/request', ['phone' => $nonexistentPhone, 'actor_type' => 'provider']);

        $this->assertSame($r1->status(), $r2->status());
        $this->assertSame($r1->json('message'), $r2->json('message'));
        // But only the real account actually got an SMS sent.
        $this->assertNotNull($this->sms->lastCodeTo($existingPhone));
        $this->assertNull($this->sms->lastCodeTo($nonexistentPhone));
    }

    public function test_provider_login_succeeds_for_an_existing_approved_provider(): void
    {
        [, $franchise, $zone] = array_values($this->makeFranchiseTree());
        $phone = '9'.fake()->unique()->numerify('#########');
        $providerUser = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => $phone, 'role' => 'provider', 'status' => 'active']);
        \App\Models\Provider::create(['user_id' => $providerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true]);

        $code = $this->requestAndCaptureCode($phone, 'provider');

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'provider', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $providerUser->id);
    }

    public function test_worker_login_succeeds_for_an_existing_field_worker(): void
    {
        [, $franchise, $zone] = array_values($this->makeFranchiseTree());
        $phone = '9'.fake()->unique()->numerify('#########');
        $workerUser = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Worker', 'phone' => $phone, 'role' => 'provider', 'status' => 'active']);
        FieldWorker::create(['user_id' => $workerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'kyc_status' => 'approved', 'is_active' => true]);

        $code = $this->requestAndCaptureCode($phone, 'field_worker');

        $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'field_worker', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $workerUser->id);
    }

    // ============================= Session management =============================

    public function test_logout_revokes_the_token(): void
    {
        $customer = $this->makeCustomer();
        $token = $customer->createToken('test')->plainTextToken;
        $this->assertSame(1, \Laravel\Sanctum\PersonalAccessToken::count());

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count(), 'The token row itself must actually be gone, not just the response claiming success.');

        // Laravel's testing HTTP kernel reuses the same booted application
        // across consecutive $this->postJson()/getJson() calls within one
        // test method — Sanctum's guard caches its resolved user on that
        // shared instance, so a second simulated request in the same test
        // can appear "still authenticated" even though the token row is
        // genuinely gone (proven above) and a REAL second HTTP request to
        // a real server would correctly be rejected. Forcing guard
        // resolution to run again here (rather than trusting the cache)
        // is what makes this assertion meaningful instead of a false pass.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user')->assertUnauthorized();
    }

    public function test_device_registration_updates_fcm_token(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/auth/device', ['fcm_token' => 'test-push-token-123'])
            ->assertOk();

        $this->assertSame('test-push-token-123', $customer->fresh()->fcm_token);
    }
}
