<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Cart\Index as CartIndex;
use App\Livewire\Customer\Catalog\ServiceIndex;
use App\Livewire\Customer\Catalog\ServiceShow;
use App\Livewire\Customer\Checkout;
use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * D5/D6 display (docs/COUPON_HARDENING_FINAL.md, Run B item 3). The server computes BOTH prices (offer and
 * full); the client only switches which one the server already sent. Online shows the offer price, cash shows
 * the full price, and the customer is told so before confirming. A forged client price or payment method can
 * never change what is charged.
 */
class OfferDisplayCashPricingTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private const NOTE = 'Offer price applies when you pay online. Pay by cash: ₹500.00.';

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);
        Setting::set('payment.wallet_enabled', '1');
        $this->makeFlashSale([$service], ['discount_type' => 'percent', 'discount_value' => 20]); // offer 400, full 500

        return compact('customer', 'address', 'service', 'franchise', 'zone');
    }

    private function atPayStep($customer, $service, $address)
    {
        return Livewire::actingAs($customer)->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)->call('next')->call('next')->call('next');
    }

    public function test_catalog_and_service_page_show_the_offer_price_with_the_cash_wording(): void
    {
        ['service' => $service] = $this->world();

        Livewire::test(ServiceIndex::class)->assertSee('400.00')->assertSee(self::NOTE);
        Livewire::test(ServiceShow::class, ['service' => $service])->assertSee('400.00')->assertSee(self::NOTE);
    }

    public function test_a_service_with_no_offer_shows_no_cash_wording(): void
    {
        $this->makeService($this->makeCategory(), ['base_price' => 500]);

        Livewire::test(ServiceIndex::class)->assertDontSee('Offer price applies when you pay online');
    }

    public function test_wizard_payable_amount_follows_the_selected_payment_method(): void
    {
        ['customer' => $c, 'service' => $s, 'address' => $a] = $this->world();

        $this->atPayStep($c, $s, $a)
            ->set('paymentMethod', 'online')->assertViewHas('baseEstimate', 400.0)
            ->set('paymentMethod', 'cash')->assertViewHas('baseEstimate', 500.0)->assertSee(self::NOTE)
            ->set('paymentMethod', 'wallet')->assertViewHas('baseEstimate', 400.0);
    }

    public function test_checkout_payable_total_follows_the_selected_payment_method(): void
    {
        ['customer' => $c, 'service' => $s, 'address' => $a] = $this->world();
        app(\App\Services\Customer\ServiceCartService::class)->add($c, $s, quantity: 2);

        Livewire::actingAs($c)->test(Checkout::class)
            ->set('addressId', $a->id)->call('next')->call('next')->call('next')
            ->set('paymentMethod', 'online')->assertViewHas('reviewTotal', 800.0)
            ->set('paymentMethod', 'cash')->assertViewHas('reviewTotal', 1000.0)
            ->assertSee('Offer price applies when you pay online. Pay by cash: ₹1,000.00.');
    }

    public function test_cart_shows_the_full_price_wording(): void
    {
        ['customer' => $c, 'service' => $s] = $this->world();
        app(\App\Services\Customer\ServiceCartService::class)->add($c, $s, quantity: 2);

        Livewire::actingAs($c)->test(CartIndex::class)
            ->assertSee('Offer price applies when you pay online. Pay by cash: ₹1,000.00.');
    }

    public function test_api_payload_carries_both_prices(): void
    {
        ['service' => $service] = $this->world();

        $row = collect($this->getJson('/api/services')->assertOk()->json('data'))->firstWhere('id', $service->id);

        $this->assertEquals(400, $row['effective_price']);
        $this->assertEquals(500, $row['cash_price']);
        $this->assertTrue($row['offer_requires_online']);
    }

    public function test_a_forged_client_price_or_payment_method_cannot_change_what_is_charged(): void
    {
        ['customer' => $c, 'service' => $s, 'address' => $a] = $this->world();
        Queue::fake();

        // Forged price keys on the API payload are ignored: cash is charged the full price, online the offer.
        $forged = ['service_id' => $s->id, 'address_id' => $a->id, 'price_quoted' => 1, 'price' => 1, 'effective_price' => 1, 'cash_price' => 1];
        $this->actingAs($c, 'sanctum')->postJson('/api/bookings', $forged + ['payment_method' => 'cash'])->assertStatus(201);
        $cash = Booking::latest('id')->firstOrFail();
        $this->actingAs($c, 'sanctum')->postJson('/api/bookings', $forged + ['payment_method' => 'online'])->assertStatus(201);
        $online = Booking::latest('id')->firstOrFail();
        $this->assertEquals(500.0, (float) $cash->price_quoted);
        $this->assertEquals(400.0, (float) $online->price_quoted);

        // Through the wizard: the screen shows 400 for online, but the customer then flips to cash and confirms —
        // the charge is the server's cash price, whatever the page displayed a moment earlier.
        $this->atPayStep($c, $s, $a)
            ->set('paymentMethod', 'online')->assertViewHas('baseEstimate', 400.0)
            ->set('paymentMethod', 'cash')
            ->call('placeBooking');

        $wizardBooking = Booking::where('customer_id', $c->id)->latest('id')->first();
        $this->assertSame('cash', $wizardBooking->payment_method);
        $this->assertEquals(500.0, (float) $wizardBooking->price_quoted);
    }
}
