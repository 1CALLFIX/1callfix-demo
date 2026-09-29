<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\PaymentGatewayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-RAZORPAY-KEY-NAME-FIX-001 — the booking checkout page's Razorpay init
 * payload must carry the public key. The driver returns it as `key_id`; the
 * page's JS used to read only `razorpay_key_id` (undefined) and Checkout.js
 * failed with "Authentication key was missing during initialization".
 */
class CustomerOrderRazorpayKeyTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function pendingBooking($customer): Booking
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']));
        $address = $this->makeAddress($customer, $franchise, $zone);

        return Booking::create([
            'code' => 'RK-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'pending', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);
    }

    private function fakeRazorpayOrders(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(
            ['id' => 'order_TEST123', 'amount' => 50000, 'currency' => 'INR'], 200
        )]);
    }

    /** What the page's JS resolves: `o.razorpay_key_id ?? o.key_id`. */
    private function resolvedKey(array $order): ?string
    {
        return $order['razorpay_key_id'] ?? $order['key_id'] ?? null;
    }

    public function test_init_payload_key_matches_env_config_when_no_db_row_exists(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_ENVKEY123456',
            'services.razorpay.key_secret' => 'env_secret',
            'services.razorpay.webhook_secret' => 'env_whsec',
        ]);
        $this->fakeRazorpayOrders();
        $me = $this->makeCustomer();
        $booking = $this->pendingBooking($me);

        $component = Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->call('startPayment')
            ->assertDispatched('razorpay-open');

        $order = $component->effects['dispatches'][0]['params']['order'];
        $this->assertSame(config('services.razorpay.key_id'), $this->resolvedKey($order));
    }

    public function test_init_payload_key_matches_active_db_row_key_id(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_ENVKEY123456',
            'services.razorpay.key_secret' => 'env_secret',
            'services.razorpay.webhook_secret' => 'env_whsec',
        ]);
        PaymentGatewayConfig::create([
            'name' => 'Live', 'driver' => 'razorpay', 'mode' => 'live', 'is_active' => true, 'priority' => 1,
            'credentials' => ['key_id' => 'rzp_live_DBKEY654321', 'key_secret' => 'db_secret', 'webhook_secret' => 'db_whsec'],
        ]);
        $this->fakeRazorpayOrders();
        $me = $this->makeCustomer();
        $booking = $this->pendingBooking($me);

        $component = Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->call('startPayment')
            ->assertDispatched('razorpay-open');

        $order = $component->effects['dispatches'][0]['params']['order'];
        $this->assertSame('rzp_live_DBKEY654321', $this->resolvedKey($order));
    }

    public function test_page_js_falls_back_to_key_id(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/customer/orders/show.blade.php'));

        $this->assertStringContainsString('key: o.razorpay_key_id ?? o.key_id,', $blade);
    }
}
