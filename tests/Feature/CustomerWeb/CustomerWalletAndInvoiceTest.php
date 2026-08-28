<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Wallet\Index as WalletIndex;
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

    public function test_guests_cannot_see_the_wallet(): void
    {
        $this->get(route('customer.wallet'))->assertRedirect(route('customer.login'));
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
