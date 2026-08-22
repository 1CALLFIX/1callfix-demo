<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentGateway;
use App\Models\PaymentGatewayConfig;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaytmPaymentDriver;
use App\Services\Payments\RazorpayPaymentDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Payment Gateway Manager session. The load-bearing guarantee this suite
 * exists to prove: an empty `payment_gateways` table (every environment on
 * day one) resolves to the EXACT same env-config-driven RazorpayPaymentDriver
 * that PaymentGatewayTest.php's whole existing 20-test suite already
 * exercises — this refactor changes nothing about that path. Everything
 * else here is the new, additive behaviour (a DB-configured gateway
 * actually taking over once an admin activates one).
 */
class PaymentGatewayManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_envfallback123',
            'services.razorpay.key_secret' => 'env-fallback-secret-never-real',
            'services.razorpay.webhook_secret' => 'env-fallback-webhook-secret',
        ]);
    }

    public function test_falls_back_to_env_config_when_no_gateway_row_is_active(): void
    {
        $gateway = app(PaymentGateway::class);

        $this->assertInstanceOf(RazorpayPaymentDriver::class, $gateway);
        $this->assertTrue($gateway->isConfigured());
        // maskedPublicIdentifier() only ever reveals the first 12 characters
        // (see RazorpayPaymentDriver's own docblock) — asserting against
        // that same prefix, not the full key, matches its real masking
        // behaviour rather than assuming it echoes the whole value back.
        $this->assertStringStartsWith(substr('rzp_test_envfallback123', 0, 12), $gateway->maskedPublicIdentifier());
    }

    public function test_an_inactive_row_is_never_selected_env_fallback_still_wins(): void
    {
        PaymentGatewayConfig::create([
            'name' => 'Staged Razorpay', 'driver' => 'razorpay', 'mode' => 'test', 'is_active' => false, 'priority' => 100,
            'credentials' => ['key_id' => 'rzp_test_shouldnotwin', 'key_secret' => 'x', 'webhook_secret' => 'y'],
        ]);

        $gateway = app(PaymentGateway::class);

        // maskedPublicIdentifier() only ever reveals the first 12 characters
        // (see RazorpayPaymentDriver's own docblock) — asserting against
        // that same prefix, not the full key, matches its real masking
        // behaviour rather than assuming it echoes the whole value back.
        $this->assertStringStartsWith(substr('rzp_test_envfallback123', 0, 12), $gateway->maskedPublicIdentifier());
    }

    public function test_an_active_razorpay_row_wins_and_its_own_credentials_are_actually_used(): void
    {
        PaymentGatewayConfig::create([
            'name' => 'DB Razorpay', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => true, 'priority' => 10,
            'credentials' => ['key_id' => 'rzp_live_dbrow999', 'key_secret' => 'db-row-secret', 'webhook_secret' => 'db-row-webhook'],
        ]);

        $gateway = app(PaymentGateway::class);
        $this->assertStringStartsWith(substr('rzp_live_dbrow999', 0, 12), $gateway->maskedPublicIdentifier());

        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_1', 'amount' => 10000, 'currency' => 'INR'], 200)]);
        $gateway->createRawOrder(100, 'receipt-1');

        $expectedAuth = 'Basic '.base64_encode('rzp_live_dbrow999:db-row-secret');
        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === $expectedAuth);
    }

    public function test_multiple_active_rows_the_highest_priority_one_wins(): void
    {
        PaymentGatewayConfig::create([
            'name' => 'Low Priority', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => true, 'priority' => 1,
            'credentials' => ['key_id' => 'rzp_live_lowpriority', 'key_secret' => 'low', 'webhook_secret' => 'low'],
        ]);
        PaymentGatewayConfig::create([
            'name' => 'High Priority', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => true, 'priority' => 50,
            'credentials' => ['key_id' => 'rzp_live_highpriority', 'key_secret' => 'high', 'webhook_secret' => 'high'],
        ]);

        $gateway = app(PaymentGateway::class);

        $this->assertStringStartsWith(substr('rzp_live_highpriority', 0, 12), $gateway->maskedPublicIdentifier());
    }

    public function test_ties_broken_by_id_ascending_first_configured_wins(): void
    {
        $first = PaymentGatewayConfig::create([
            'name' => 'First', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => true, 'priority' => 5,
            'credentials' => ['key_id' => 'rzp_live_firstrow', 'key_secret' => 'a', 'webhook_secret' => 'a'],
        ]);
        PaymentGatewayConfig::create([
            'name' => 'Second', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => true, 'priority' => 5,
            'credentials' => ['key_id' => 'rzp_live_secondrow', 'key_secret' => 'b', 'webhook_secret' => 'b'],
        ]);

        $gateway = app(PaymentGateway::class);

        $this->assertStringStartsWith(substr('rzp_live_firstrow', 0, 12), $gateway->maskedPublicIdentifier());
        $this->assertNotNull($first);
    }

    /**
     * Paytm isn't in PaymentGatewayManager::ACTIVATABLE_DRIVERS yet — even
     * if is_active is somehow true in the DB (bypassing the admin screen's
     * own guard directly, as this test does), the manager must still
     * refuse to hand it out and fall back to Razorpay/env, never silently
     * hand a real checkout flow to a driver whose every action method
     * throws.
     */
    public function test_an_active_but_not_yet_activatable_paytm_row_is_never_handed_out(): void
    {
        PaymentGatewayConfig::create([
            'name' => 'Paytm (staged)', 'driver' => 'paytm', 'mode' => 'test', 'is_active' => true, 'priority' => 999,
            'credentials' => ['merchant_id' => 'x', 'merchant_key' => 'y', 'website' => 'z'],
        ]);

        $gateway = app(PaymentGateway::class);

        $this->assertInstanceOf(RazorpayPaymentDriver::class, $gateway);
        $this->assertNotInstanceOf(PaytmPaymentDriver::class, $gateway);
    }

    public function test_manager_lists_known_drivers_for_the_admin_screen(): void
    {
        $drivers = app(PaymentGatewayManager::class)->knownDrivers();

        $this->assertArrayHasKey('razorpay', $drivers);
        $this->assertArrayHasKey('paytm', $drivers);
        $this->assertArrayHasKey('phonepe', $drivers);
    }
}
