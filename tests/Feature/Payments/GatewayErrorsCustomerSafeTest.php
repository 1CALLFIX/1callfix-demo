<?php

namespace Tests\Feature\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Livewire\Customer\Bundles\Show as BundleShow;
use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Services\Payments\RazorpayPaymentDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 fixes item 3 — a customer never sees gateway text, receipt ids or exception messages on a pay path. When
 * Razorpay refuses an order the customer gets one generic sentence; the gateway's body and the receipt go to the log.
 */
class GatewayErrorsCustomerSafeTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private const SAFE = 'We could not start the payment right now. Please try again in a moment.';

    private const LEAKS = ['Razorpay', 'razorpay', 'receipt', 'BAD_REQUEST', 'Authentication failed', 'bundle-', 'RuntimeException', 'error":'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.razorpay.key_id' => 'rzp_test_failkey123',
            'services.razorpay.key_secret' => 'fake-fail-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-fail-webhook-secret-never-real',
        ]);
        Queue::fake();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(
            ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']], 401,
        )]);
    }

    private function assertNoLeak(string $text): void
    {
        foreach (self::LEAKS as $needle) {
            $this->assertStringNotContainsString($needle, $text, "Leaked [{$needle}]");
        }
    }

    private function bundle(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $a = $this->makeService($category, ['base_price' => 499]);
        $b = $this->makeService($category, ['base_price' => 299]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')->postJson('/api/booking-bundles', [
            'payment_method' => 'online',
            'services' => [['service_id' => $a->id, 'address_id' => $address->id], ['service_id' => $b->id, 'address_id' => $address->id]],
        ])->assertStatus(201);

        return [BookingBundle::firstOrFail(), $customer, $franchise, $zone, $a, $address];
    }

    public function test_the_driver_throws_a_safe_exception_and_logs_the_gateway_detail(): void
    {
        Log::spy();

        try {
            app(RazorpayPaymentDriver::class)->createRawOrder(100.0, 'bundle-SECRET-1', []);
            $this->fail('Expected a gateway exception.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame(self::SAFE, $e->getMessage());
            $this->assertStringContainsString('bundle-SECRET-1', $e->detail);
            $this->assertStringContainsString('Authentication failed', $e->detail);
        }

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains(json_encode($context), 'bundle-SECRET-1'))->once();
    }

    public function test_the_bundle_pay_page_shows_the_generic_sentence_only(): void
    {
        [$bundle, $customer] = $this->bundle();

        $component = Livewire::actingAs($customer)->test(BundleShow::class, ['bundle' => $bundle])->call('payNow');

        $this->assertSame(self::SAFE, $component->get('error'));
        $this->assertNoLeak($component->html());
    }

    public function test_the_bundle_pay_api_returns_the_generic_sentence_only(): void
    {
        [$bundle, $customer] = $this->bundle();

        $res = $this->actingAs($customer, 'sanctum')->postJson("/api/booking-bundles/{$bundle->id}/pay/create-order");

        $this->assertSame(409, $res->status());
        $this->assertSame(self::SAFE, $res->json('message'));
        $this->assertNoLeak($res->getContent());
    }

    public function test_the_booking_order_page_shows_the_generic_sentence_only(): void
    {
        [, $customer, $franchise, $zone, $service, $address] = $this->bundle();
        $booking = Booking::create([
            'code' => 'GF-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'pending', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        $component = Livewire::actingAs($customer)->test(OrderShow::class, ['booking' => $booking])->call('startPayment');

        $this->assertSame(self::SAFE, $component->get('error'));
        $this->assertNoLeak($component->html());
    }

    public function test_the_single_booking_pay_api_and_wallet_topup_api_show_nothing_internal(): void
    {
        [, $customer, $franchise, $zone, $service, $address] = $this->bundle();
        $booking = Booking::create([
            'code' => 'GF-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'pending', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        $pay = $this->actingAs($customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/pay/create-order");
        $this->assertNoLeak($pay->getContent());

        $topUp = $this->actingAs($customer, 'sanctum')->postJson('/api/wallet/topup', ['amount' => 100]);
        $this->assertNoLeak($topUp->getContent());
    }
}
