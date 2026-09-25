<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Earnings\Wallet as WalletIndex;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E6 — wallet screen + web invoice download.
 *
 * The wallet screen reads balance/ledger from WalletService and the
 * wallet_transactions rows; top-up delegates to WalletTopUpService (and
 * needs a configured gateway). The invoice route reuses the exact
 * DocumentService the API DocumentController uses, with the same
 * 404-not-403 ownership rule.
 */
class CustomerWalletAndInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // EARN3: the wallet screen is Earnings → Wallet, behind its switches.
        \App\Models\Setting::set('earnings.enabled', '1');
        \App\Models\Setting::set('earnings.wallet_tab', '1');
    }

    use \Tests\Feature\Support\WithLegacyWalletTopUp;
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_wallet_screen_shows_balance_and_ledger(): void
    {
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 750, 'Test top-up', 'test:1');
        app(WalletService::class)->debit($customer, 100, 'Test spend', 'test:2');

        Livewire::actingAs($customer)->test(WalletIndex::class)
            ->assertSee('650.00')       // 750 - 100
            ->assertSee('Test top-up')
            ->assertSee('Test spend');
    }

    public function test_top_up_is_refused_when_no_gateway_is_configured(): void
    {
        $customer = $this->makeCustomer();

        Livewire::actingAs($customer)->test(WalletIndex::class)
            ->set('topUpAmount', '200')
            ->call('requestTopUp')
            ->assertSee('not configured');
    }

    /**
     * 1CF-LAUNCH-20260924-WALLET-MEMBERSHIP: the Razorpay handler used to be
     * bound inside a 'livewire:init' listener, which never fires again after
     * a wire:navigate visit (Account -> Wallet), so "Add money" created the
     * order and then nothing opened. The handler now lives in @script and
     * listens with $wire.on, so the event must be dispatched ->self().
     */
    public function test_top_up_opens_checkout_via_a_component_scoped_event(): void
    {
        $this->app->instance(\App\Contracts\PaymentGateway::class, $this->fakeGateway());
        $customer = $this->makeCustomer();

        $component = Livewire::actingAs($customer)->test(WalletIndex::class)
            ->set('topUpAmount', '500')
            ->call('requestTopUp')
            ->assertSet('error', '')
            ->assertDispatched('razorpay-open');

        $dispatch = collect($component->effects['dispatches'] ?? [])->firstWhere('name', 'razorpay-open');
        $this->assertTrue($dispatch['self'] ?? false, 'razorpay-open must be dispatched ->self() for $wire.on to receive it');
        $this->assertSame('order_fake123', $dispatch['params']['order']['razorpay_order_id']);

        $this->assertDatabaseHas('payments', [
            'user_id' => $customer->id, 'purpose' => 'wallet_topup', 'status' => 'pending', 'gateway_order_id' => 'order_fake123',
        ]);
    }

    public function test_checkout_handlers_do_not_depend_on_livewire_init(): void
    {
        foreach (['earnings/wallet', 'orders/show', 'bundles/show'] as $view) {
            $source = file_get_contents(resource_path("views/livewire/customer/{$view}.blade.php"));

            $this->assertStringNotContainsString("addEventListener('livewire:init'", $source, "{$view} binds its checkout handler on livewire:init, which is dead after wire:navigate");
            $this->assertStringContainsString('@script', $source, $view);
            $this->assertStringContainsString('$wire.on(', $source, $view);
        }
    }

    private function fakeGateway(): \App\Contracts\PaymentGateway
    {
        return new class implements \App\Contracts\PaymentGateway
        {
            public function identifier(): string { return 'razorpay'; }

            public function displayName(): string { return 'Fake'; }

            public function isConfigured(): bool { return true; }

            public function maskedPublicIdentifier(): ?string { return null; }

            public function checkoutKeyId(): ?string { return 'rzp_test_fake'; }

            public function createOrder(Booking $booking): array { return $this->createRawOrder((float) $booking->price_quoted, 'b'); }

            public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array
            {
                return ['razorpay_order_id' => 'order_fake123', 'key_id' => 'rzp_test_fake', 'amount' => (int) round($amountRupees * 100), 'currency' => 'INR'];
            }

            public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool { return false; }

            public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool { return false; }

            public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array { return []; }
        };
    }

    public function test_guests_cannot_see_the_wallet(): void
    {
        $this->get(route('customer.earnings.wallet'))->assertRedirect(route('customer.login'));
    }

    // ------------------------------------------------------------- invoice

    private function paidBooking(\App\Models\User $customer): Booking
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']));
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = Booking::create([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'completed', 'completed_at' => now(),
            'price_quoted' => 500, 'price_final' => 500,
            'payment_status' => 'paid', 'payment_method' => 'online',
        ]);

        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.$booking->id,
            'status' => 'captured', 'captured_at' => now(),
        ]);

        return $booking;
    }

    public function test_the_owner_can_download_a_receipt_pdf_for_a_captured_payment(): void
    {
        $customer = $this->makeCustomer();
        $booking = $this->paidBooking($customer);

        $response = $this->actingAs($customer)->get(route('customer.orders.invoice', $booking));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_a_non_owner_gets_a_404_for_someone_elses_invoice(): void
    {
        $owner = $this->makeCustomer();
        $booking = $this->paidBooking($owner);
        $stranger = $this->makeCustomer();

        $this->actingAs($stranger)->get(route('customer.orders.invoice', $booking))->assertNotFound();
    }

    public function test_a_booking_with_no_captured_payment_has_no_invoice(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(['module' => 'service']));
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = Booking::create([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'completed', 'completed_at' => now(), 'price_quoted' => 500, 'price_final' => 500,
            'payment_status' => 'pending', 'payment_method' => 'cash',
        ]);

        $this->actingAs($customer)->get(route('customer.orders.invoice', $booking))->assertNotFound();
    }
}
