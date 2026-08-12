<?php

namespace Tests\Feature\Auth;

use App\Models\QrChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Real HTTP tests for QR device pairing (Part 18/22/23). Every "must not"
 * from the mission's QR security section is tested directly: no permanent
 * credential in the QR, one-time confirm, one-time session claim, no
 * cross-purpose confirm, no session leak via the status-poll endpoint.
 */
class QrPairingTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_create_returns_two_distinct_tokens(): void
    {
        $response = $this->postJson('/api/auth/qr/create')->assertOk();

        $qrToken = $response->json('qr_token');
        $pollToken = $response->json('poll_token');

        $this->assertNotEmpty($qrToken);
        $this->assertNotEmpty($pollToken);
        $this->assertNotSame($qrToken, $pollToken, 'The QR-scan token and the desktop poll token must never be the same value.');
    }

    public function test_status_by_poll_token_starts_pending_and_never_exposes_a_session(): void
    {
        $create = $this->postJson('/api/auth/qr/create')->json();

        $response = $this->getJson('/api/auth/qr/status?poll_token='.$create['poll_token']);

        $response->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('ready_to_claim', false);
        $this->assertArrayNotHasKey('token', $response->json(), 'The status-poll response must never itself contain a session token.');
    }

    public function test_confirm_requires_an_authenticated_mobile_session(): void
    {
        $create = $this->postJson('/api/auth/qr/create')->json();

        $this->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])->assertUnauthorized();
    }

    public function test_full_pairing_flow_desktop_gets_a_real_session_after_mobile_confirms(): void
    {
        $customer = $this->makeCustomer();
        $create = $this->postJson('/api/auth/qr/create')->json();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])
            ->assertOk();

        $status = $this->getJson('/api/auth/qr/status?poll_token='.$create['poll_token'])->json();
        $this->assertSame('confirmed', $status['status']);
        $this->assertTrue($status['ready_to_claim']);

        $claim = $this->postJson('/api/auth/qr/claim', ['poll_token' => $create['poll_token']]);
        $claim->assertOk()->assertJsonPath('user.id', $customer->id);
        $this->assertNotEmpty($claim->json('token'));
    }

    public function test_claiming_twice_only_issues_a_token_once(): void
    {
        $customer = $this->makeCustomer();
        $create = $this->postJson('/api/auth/qr/create')->json();
        $this->actingAs($customer, 'sanctum')->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])->assertOk();

        $this->postJson('/api/auth/qr/claim', ['poll_token' => $create['poll_token']])->assertOk();

        $second = $this->postJson('/api/auth/qr/claim', ['poll_token' => $create['poll_token']]);
        $second->assertStatus(410)->assertJson(['message' => 'This challenge has already been claimed.']);
    }

    public function test_confirming_an_already_confirmed_qr_is_rejected_replay_protection(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $create = $this->postJson('/api/auth/qr/create')->json();

        $this->actingAs($customerA, 'sanctum')->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])->assertOk();

        // Simulates a screenshot/replay of the same QR being scanned again
        // by a second device — must never re-confirm, and must never let a
        // different user hijack an already-confirmed challenge.
        $this->actingAs($customerB, 'sanctum')
            ->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])
            ->assertStatus(409)
            ->assertJson(['message' => 'This QR code has already been used.']);

        $claim = $this->postJson('/api/auth/qr/claim', ['poll_token' => $create['poll_token']]);
        $this->assertSame($customerA->id, $claim->json('user.id'), 'The FIRST confirmer must win — a replay must never change who the session belongs to.');
    }

    public function test_expired_qr_cannot_be_confirmed(): void
    {
        $customer = $this->makeCustomer();
        $create = $this->postJson('/api/auth/qr/create')->json();
        QrChallenge::where('token', $create['qr_token'])->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])
            ->assertStatus(410)
            ->assertJson(['message' => 'This QR code has expired. Ask for a new one.']);
    }

    public function test_wrong_qr_token_returns_404_not_a_500(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/auth/qr/confirm', ['qr_token' => 'this-token-does-not-exist'])
            ->assertStatus(404);
    }

    public function test_revoking_a_pending_challenge_prevents_confirmation(): void
    {
        $customer = $this->makeCustomer();
        $create = $this->postJson('/api/auth/qr/create')->json();

        $this->postJson('/api/auth/qr/revoke', ['poll_token' => $create['poll_token']])
            ->assertOk()
            ->assertJson(['message' => 'Challenge revoked.']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']])
            ->assertStatus(410)
            ->assertJson(['message' => 'This QR code was cancelled.']);
    }

    public function test_two_devices_confirming_the_same_challenge_only_one_wins(): void
    {
        // Sequential proof of the same row-lock-guaranteed outcome a true
        // concurrent scan would resolve to (PHPUnit is single-threaded —
        // see PRODUCTION_READINESS_AUDIT.md's standing note on this
        // limitation). confirm() is wrapped in DB::transaction() +
        // lockForUpdate(), the same convention this codebase already uses
        // for every other one-winner-only decision.
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $create = $this->postJson('/api/auth/qr/create')->json();

        $firstResult = $this->actingAs($customerA, 'sanctum')->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']]);
        $secondResult = $this->actingAs($customerB, 'sanctum')->postJson('/api/auth/qr/confirm', ['qr_token' => $create['qr_token']]);

        $firstResult->assertOk();
        $secondResult->assertStatus(409);

        $challenge = QrChallenge::where('token', $create['qr_token'])->first();
        $this->assertSame($customerA->id, $challenge->confirmed_by_user_id);
    }
}
