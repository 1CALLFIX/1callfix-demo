<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-002/003-AUDIT follow-up — the LAUNCH-002 verification
 * pass found that the original suspension fix (f4d8d12) never touched
 * AuthController::password()/firebase(), the endpoints a native/API client
 * actually authenticates through. This proves users.status is now read
 * from the authoritative database record (never trusted from the client —
 * the request body carries no status field at all) at every point a fresh
 * Sanctum token could be minted:
 *
 *   - password() — the plain password-login branch
 *   - firebase() — the "existing account WITH a password" phone-login
 *     branch, which links the Firebase identity before this fix; the check
 *     runs BEFORE that link, same ordering fix f4d8d12 already applied to
 *     the customer Livewire GoogleAuth::phoneTokenReceived() path
 *   - firebase() — handleGoogleFirstLeg()'s "already linked" branch
 *   - firebase() — linkOrCreateGoogleAccount()'s "existing account" branch
 *
 * Paired with an active-account regression per branch.
 */
class SuspendedAccountApiAuthTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        Notification::fake();
    }

    // ============================== POST /api/auth/password ==============================

    public function test_suspended_user_cannot_authenticate_through_password_api(): void
    {
        $user = $this->passwordCustomer('good-password-1', ['status' => 'suspended']);

        $this->postJson('/api/auth/password', [
            'identifier' => $user->phone, 'password' => 'good-password-1', 'actor_type' => 'customer',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'This account has been suspended. Contact support for help.')
            ->assertJsonMissing(['token']);
    }

    public function test_active_user_can_authenticate_through_password_api(): void
    {
        $user = $this->passwordCustomer('good-password-1');

        $this->postJson('/api/auth/password', [
            'identifier' => $user->phone, 'password' => 'good-password-1', 'actor_type' => 'customer',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    // ============================== POST /api/auth/firebase (phone login) ==============================

    public function test_suspended_firebase_user_cannot_authenticate(): void
    {
        $user = $this->passwordCustomer('x', ['status' => 'suspended']);
        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account has been suspended. Contact support for help.')
            ->assertJsonMissing(['token']);

        $this->assertNull($user->fresh()->firebase_uid, 'A suspended account must not get its Firebase identity linked either.');
    }

    public function test_active_firebase_user_can_authenticate(): void
    {
        $user = $this->passwordCustomer('x');
        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    // ============================== POST /api/auth/firebase (Google, already linked) ==============================

    public function test_suspended_user_cannot_complete_google_first_leg_when_already_linked(): void
    {
        $user = $this->passwordCustomer('x', [
            'status' => 'suspended', 'email' => 'suspended-g@example.test',
            'google_id' => 'guid-g1', 'firebase_uid' => 'guid-g1',
        ]);
        $token = $this->firebase->issueGoogleToken($user->email, uid: 'guid-g1');

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account has been suspended. Contact support for help.')
            ->assertJsonMissing(['token']);
    }

    public function test_active_user_can_complete_google_first_leg_when_already_linked(): void
    {
        $user = $this->passwordCustomer('x', [
            'email' => 'active-g@example.test', 'google_id' => 'guid-g2', 'firebase_uid' => 'guid-g2',
        ]);
        $token = $this->firebase->issueGoogleToken($user->email, uid: 'guid-g2');

        $this->postJson('/api/auth/firebase', ['id_token' => $token, 'actor_type' => 'customer'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    // ============================== POST /api/auth/firebase (Google + phone second leg) ==============================

    public function test_suspended_user_cannot_complete_google_plus_phone_link(): void
    {
        $user = $this->passwordCustomer('x', ['status' => 'suspended', 'email' => 'suspended-link@example.test']);
        $googleToken = $this->firebase->issueGoogleToken($user->email, 'Suspended', 'guid-link-s');
        $phoneToken = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $this->postJson('/api/auth/firebase', [
            'id_token' => $phoneToken, 'google_id_token' => $googleToken, 'actor_type' => 'customer',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'This account has been suspended. Contact support for help.')
            ->assertJsonMissing(['token']);

        $this->assertNull($user->fresh()->google_id, 'A suspended account must not get linked either.');
    }

    public function test_active_user_can_complete_google_plus_phone_link(): void
    {
        $user = $this->passwordCustomer('x', ['email' => 'active-link@example.test']);
        $googleToken = $this->firebase->issueGoogleToken($user->email, 'Active', 'guid-link-a');
        $phoneToken = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $this->postJson('/api/auth/firebase', [
            'id_token' => $phoneToken, 'google_id_token' => $googleToken, 'actor_type' => 'customer',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->assertSame('guid-link-a', $user->fresh()->google_id);
    }

    // ============================== Client cannot supply status ==============================

    public function test_client_supplied_status_field_is_ignored(): void
    {
        $user = $this->passwordCustomer('good-password-1', ['status' => 'suspended']);

        // A malicious/naive client tries to smuggle an active status in the
        // request body — the controller never reads a "status" input field
        // at all, only $user->status from the DB, so this must still fail.
        $this->postJson('/api/auth/password', [
            'identifier' => $user->phone, 'password' => 'good-password-1',
            'actor_type' => 'customer', 'status' => 'active',
        ])->assertStatus(403);

        $this->assertSame('suspended', $user->fresh()->status);
    }
}
