<?php

namespace Tests\Feature\PaymentGateways;

use App\Livewire\PaymentGateways\Manage;
use App\Models\PaymentGatewayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Payment Gateway Manager session — the admin screen. `payment_gateways.manage`
 * is seeded (2026_08_25_002000) to super_admin only, unlike every other
 * permission in this catalog, so the RBAC coverage here specifically checks
 * that an in-panel actor who merely holds SOME other permission (not this
 * one) is still refused — not just an actor with zero admin access at all
 * (that's the generic EnsureHasAdminAccess case, already covered elsewhere).
 */
class PaymentGatewaysAdminScreenTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_an_in_panel_actor_without_the_permission_is_forbidden(): void
    {
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');

        $this->actingAs($actor)->get(route('admin.payment-gateways.index'))->assertForbidden();
    }

    public function test_super_admin_can_view_the_screen_without_any_explicit_grant(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->get(route('admin.payment-gateways.index'))->assertOk();
    }

    public function test_super_admin_can_add_a_razorpay_gateway_inactive_by_default(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('name', 'Razorpay Primary')
            ->set('driver', 'razorpay')
            ->set('mode', 'live')
            ->set('priority', '10')
            ->set('credentialInputs.key_id', 'rzp_live_realsecretid')
            ->set('credentialInputs.key_secret', 'super-secret-key')
            ->set('credentialInputs.webhook_secret', 'super-secret-webhook')
            ->call('save')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('payment_gateways', ['name' => 'Razorpay Primary', 'driver' => 'razorpay', 'is_active' => false]);
    }

    public function test_credentials_never_appear_in_the_rendered_screen(): void
    {
        $admin = $this->makeSuperAdmin();
        PaymentGatewayConfig::create([
            'name' => 'Razorpay Primary', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => false, 'priority' => 1,
            'credentials' => ['key_id' => 'rzp_live_visiblepart', 'key_secret' => 'ultra-secret-value-12345', 'webhook_secret' => 'ultra-secret-webhook-99999'],
        ]);

        $component = Livewire::actingAs($admin)->test(Manage::class);

        $component->assertDontSee('ultra-secret-value-12345');
        $component->assertDontSee('ultra-secret-webhook-99999');
    }

    public function test_activating_a_razorpay_gateway_succeeds(): void
    {
        $admin = $this->makeSuperAdmin();
        $gateway = PaymentGatewayConfig::create([
            'name' => 'Razorpay Primary', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => false, 'priority' => 1,
            'credentials' => ['key_id' => 'a', 'key_secret' => 'b', 'webhook_secret' => 'c'],
        ]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('toggleActive', $gateway->id)
            ->assertSet('flashType', 'success');

        $this->assertTrue($gateway->fresh()->is_active);
    }

    public function test_activating_a_paytm_gateway_is_rejected_with_the_onboarding_pending_message(): void
    {
        $admin = $this->makeSuperAdmin();
        $gateway = PaymentGatewayConfig::create([
            'name' => 'Paytm (staged)', 'driver' => 'paytm', 'mode' => 'test', 'is_active' => false, 'priority' => 1,
            'credentials' => ['merchant_id' => 'a', 'merchant_key' => 'b', 'website' => 'c'],
        ]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('toggleActive', $gateway->id)
            ->assertSet('flashType', 'error')
            ->assertSee('onboarding', false);

        $this->assertFalse($gateway->fresh()->is_active);
    }

    public function test_editing_with_a_blank_credential_field_leaves_that_value_unchanged(): void
    {
        $admin = $this->makeSuperAdmin();
        $gateway = PaymentGatewayConfig::create([
            'name' => 'Razorpay Primary', 'driver' => 'razorpay', 'mode' => 'test', 'is_active' => false, 'priority' => 1,
            'credentials' => ['key_id' => 'original_key_id', 'key_secret' => 'original_secret', 'webhook_secret' => 'original_webhook'],
        ]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('edit', $gateway->id)
            ->set('editName', 'Razorpay Primary (renamed)')
            ->set('editPriority', '20')
            // Every credential input left blank — should NOT overwrite the saved values.
            ->call('update')
            ->assertSet('flashType', 'success');

        $fresh = $gateway->fresh();
        $this->assertSame('Razorpay Primary (renamed)', $fresh->name);
        $this->assertSame(20, $fresh->priority);
        $this->assertSame('original_key_id', $fresh->credentials['key_id']);
        $this->assertSame('original_secret', $fresh->credentials['key_secret']);
        $this->assertSame('original_webhook', $fresh->credentials['webhook_secret']);
    }

    public function test_editing_with_a_filled_credential_field_overwrites_only_that_value(): void
    {
        $admin = $this->makeSuperAdmin();
        $gateway = PaymentGatewayConfig::create([
            'name' => 'Razorpay Primary', 'driver' => 'razorpay', 'mode' => 'test', 'is_active' => false, 'priority' => 1,
            'credentials' => ['key_id' => 'original_key_id', 'key_secret' => 'original_secret', 'webhook_secret' => 'original_webhook'],
        ]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('edit', $gateway->id)
            ->set('editName', 'Razorpay Primary')
            ->set('editPriority', '1')
            ->set('editCredentialInputs.key_secret', 'rotated_secret')
            ->call('update');

        $fresh = $gateway->fresh();
        $this->assertSame('original_key_id', $fresh->credentials['key_id']);
        $this->assertSame('rotated_secret', $fresh->credentials['key_secret']);
        $this->assertSame('original_webhook', $fresh->credentials['webhook_secret']);
    }

    public function test_deleting_a_gateway_removes_it(): void
    {
        $admin = $this->makeSuperAdmin();
        $gateway = PaymentGatewayConfig::create([
            'name' => 'Razorpay Primary', 'driver' => 'razorpay', 'mode' => 'test', 'is_active' => false, 'priority' => 1,
            'credentials' => ['key_id' => 'a', 'key_secret' => 'b', 'webhook_secret' => 'c'],
        ]);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('confirmDelete', $gateway->id)
            ->call('deleteGateway')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseMissing('payment_gateways', ['id' => $gateway->id]);
    }

    public function test_credentials_are_actually_encrypted_at_rest(): void
    {
        $gateway = PaymentGatewayConfig::create([
            'name' => 'Razorpay Primary', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => false, 'priority' => 1,
            'credentials' => ['key_id' => 'a', 'key_secret' => 'plaintext-should-never-appear', 'webhook_secret' => 'c'],
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('payment_gateways')->where('id', $gateway->id)->value('credentials');

        $this->assertStringNotContainsString('plaintext-should-never-appear', $raw);
    }
}
