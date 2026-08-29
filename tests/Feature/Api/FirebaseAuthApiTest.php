<?php

namespace Tests\Feature\Api;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * POST /api/auth/firebase — the unified token endpoint: phone-auth login,
 * self-registration, and Google sign-in (with the mandatory mobile step).
 */
class FirebaseAuthApiTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        Notification::fake();
    }

    public function test_phone_token_for_an_existing_customer_returns_a_token(): void
    {
        $user = $this->passwordCustomer('x');
        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);
    }

    public function test_phone_token_for_an_unknown_number_asks_for_registration_details(): void
    {
        $token = $this->firebase->issuePhoneToken($this->e164($this->randomPhone()));

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertStatus(404)
            ->assertJsonPath('needs_registration', true);
    }

    public function test_phone_token_with_name_and_password_registers_a_customer(): void
    {
        $phone = $this->randomPhone();
        $token = $this->firebase->issuePhoneToken($this->e164($phone));

        $this->postJson('/api/auth/firebase', [
            'id_token' => $token, 'actor_type' => 'customer',
            'name' => 'New Person', 'password' => 'password1234',
        ])->assertOk()->assertJsonPath('user.phone', $phone);

        $user = User::where('phone', $phone)->sole();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password1234', $user->password));
    }

    public function test_register_endpoint_creates_a_customer_from_a_verified_phone(): void
    {
        $phone = $this->randomPhone();
        $token = $this->firebase->issuePhoneToken($this->e164($phone));

        $this->postJson('/api/auth/register', [
            'id_token' => $token, 'name' => 'Reg Person', 'password' => 'password1234',
        ])->assertCreated()->assertJsonPath('user.phone', $phone);
    }

    public function test_an_invalid_firebase_token_is_a_validation_error(): void
    {
        $bad = 'fake-nonsense';
        $this->firebase->rejectToken($bad);

        $this->postJson('/api/auth/firebase', ['id_token' => $bad, 'actor_type' => 'customer'])
            ->assertStatus(422)->assertJsonValidationErrors(['id_token']);
    }

    public function test_provider_actor_type_requires_a_pre_existing_profile(): void
    {
        $phone = $this->randomPhone();
        $token = $this->firebase->issuePhoneToken($this->e164($phone));

        // No provider profile → 404, never self-registered as a provider.
        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'provider'])
            ->assertStatus(404);

        $this->assertSame(0, User::where('phone', $phone)->count());
    }

    public function test_google_token_for_a_new_identity_asks_for_a_phone(): void
    {
        $token = $this->firebase->issueGoogleToken('newg@example.com', 'New G', 'guid-x');

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertOk()
            ->assertJsonPath('needs_phone', true);

        $this->assertSame(0, User::count());
    }

    public function test_google_plus_phone_creates_the_account(): void
    {
        $phone = $this->randomPhone();
        $googleToken = $this->firebase->issueGoogleToken('combo@example.com', 'Combo', 'guid-combo');
        $phoneToken = $this->firebase->issuePhoneToken($this->e164($phone));

        $this->postJson('/api/auth/firebase', [
            'id_token' => $phoneToken,
            'google_id_token' => $googleToken,
            'actor_type' => 'customer',
        ])->assertOk()->assertJsonPath('user.email', 'combo@example.com');

        $user = User::where('email', 'combo@example.com')->sole();
        $this->assertSame($phone, $user->phone);
        $this->assertSame('guid-combo', $user->google_id);
    }

    public function test_google_email_matching_an_existing_account_needs_a_phone_link(): void
    {
        $existing = $this->passwordCustomer('x', ['email' => 'known@example.com']);
        $googleToken = $this->firebase->issueGoogleToken('known@example.com', 'K', 'guid-known');

        $this->postJson('/api/auth/firebase', ['id_token' => $googleToken, 'actor_type' => 'customer'])
            ->assertStatus(409)
            ->assertJsonPath('needs_phone_link', true);

        $this->assertNull($existing->fresh()->google_id);

        // Verifying the account's own number links it.
        $phoneToken = $this->firebase->issuePhoneToken($this->e164($existing->phone));
        $this->postJson('/api/auth/firebase', [
            'id_token' => $phoneToken, 'google_id_token' => $googleToken, 'actor_type' => 'customer',
        ])->assertOk()->assertJsonPath('user.id', $existing->id);

        $this->assertSame('guid-known', $existing->fresh()->google_id);
    }
}
