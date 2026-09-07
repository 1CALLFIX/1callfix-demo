<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 — the web (session-guarded) FCM token registration endpoints that
 * every web surface (customer, provider, admin) shares, plus the admin-only
 * ops-alerts opt-in flip. Before this, no web user could get an fcm_token
 * written at all — only the native /api/auth/device path did.
 */
class PushTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Web User',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer',
            'status' => 'active',
        ], $overrides));
    }

    public function test_a_guest_cannot_register_a_push_token(): void
    {
        $this->postJson('/push/token', ['token' => 'abc'])->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_store_their_web_push_token(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/push/token', ['token' => 'fcm-web-token-123'])
            ->assertOk();

        $this->assertSame('fcm-web-token-123', $user->fresh()->fcm_token);
    }

    public function test_the_token_is_required(): void
    {
        $this->actingAs($this->user())
            ->postJson('/push/token', [])
            ->assertJsonValidationErrorFor('token');
    }

    public function test_a_user_can_clear_their_token(): void
    {
        $user = $this->user(['fcm_token' => 'to-be-removed']);

        $this->actingAs($user)->deleteJson('/push/token')->assertOk();

        $this->assertNull($user->fresh()->fcm_token);
    }

    public function test_a_non_admin_cannot_flip_the_ops_alerts_preference(): void
    {
        $this->actingAs($this->user())
            ->postJson('/admin/push/ops-alerts', ['enabled' => true])
            ->assertForbidden();
    }

    public function test_an_admin_can_enable_and_disable_ops_alerts(): void
    {
        $admin = $this->user(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->postJson('/admin/push/ops-alerts', ['enabled' => true])
            ->assertOk();
        $this->assertTrue($admin->fresh()->push_ops_alerts);

        $this->actingAs($admin)
            ->postJson('/admin/push/ops-alerts', ['enabled' => false])
            ->assertOk();
        $this->assertFalse($admin->fresh()->push_ops_alerts);
    }
}
