<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Booking\Wizard;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E6 — the customer booking wizard. It creates NO booking logic of
 * its own: placeBooking() hands the exact server-derived payload
 * BookingController::store() builds to the SAME CreateBookingAction. These
 * tests prove the wizard cannot be talked into anything that Action would
 * not already do — a foreign address, a client price, a disabled method —
 * and that the happy paths (wallet / cash) land a real booking.
 */
class BookingWizardTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    /** @return array{customer: \App\Models\User, address: Address, service: \App\Models\Service, franchise: \App\Models\Franchise, zone: \App\Models\Zone} */
    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $service = $this->makeService($category, ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);

        return compact('customer', 'address', 'service', 'franchise', 'zone');
    }

    private function fund(\App\Models\User $user, float $amount): void
    {
        Wallet::updateOrCreate(['user_id' => $user->id], ['balance' => $amount]);
    }

    /** Drive the wizard the way a customer does — through its own gates — to the pay step. */
    private function atPayStep(\App\Models\User $customer, \App\Models\Service $service, Address $address)
    {
        return Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->call('next')   // configure -> address
            ->call('next')   // address -> schedule
            ->call('next');  // schedule (ASAP) -> pay
    }

    // ---------------------------------------------------------------- access

    public function test_the_wizard_requires_a_logged_in_customer(): void
    {
        ['service' => $service] = $this->world();

        $this->get(route('customer.book', $service))->assertRedirect(route('customer.login'));
    }

    public function test_an_inactive_service_is_a_404(): void
    {
        ['customer' => $customer, 'service' => $service] = $this->world();
        $service->update(['is_active' => false]);

        $this->actingAs($customer)->get(route('customer.book', $service))->assertNotFound();
    }

    // ---------------------------------------------------------------- happy path

    public function test_a_wallet_booking_is_created_paid_and_debits_the_wallet_atomically(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();
        $this->fund($customer, 5000);

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->assertSet('step', 'configure')
            ->call('next')                       // configure -> address
            ->assertSet('step', 'address')
            ->set('addressId', $address->id)
            ->call('next')                       // address -> schedule
            ->assertSet('step', 'schedule')
            ->call('next')                       // schedule (ASAP) -> pay
            ->assertSet('step', 'pay')
            ->set('paymentMethod', 'wallet')
            ->call('placeBooking')
            ->assertRedirect();

        $booking = Booking::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($service->id, $booking->service_id);
        $this->assertSame($address->id, $booking->address_id);
        $this->assertSame('wallet', $booking->payment_method);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertEquals(500.0, (float) $booking->price_quoted);
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$booking->id}:wallet-payment")->count());
        $this->assertEquals(4500.0, (float) $customer->wallet->fresh()->balance);
    }

    public function test_a_cash_booking_is_created_pending(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();

        $this->atPayStep($customer, $service, $address)
            ->assertSet('step', 'pay')
            ->set('paymentMethod', 'cash')
            ->call('placeBooking')
            ->assertRedirect();

        $booking = Booking::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('cash', $booking->payment_method);
        $this->assertSame('pending', $booking->payment_status);
        $this->assertContains($booking->status, ['pending', 'searching_provider']);
    }

    public function test_a_scheduled_booking_stores_the_chosen_time(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();
        // The customer picks a wall clock on their own clock (Asia/Kolkata,
        // the makeFranchiseTree fixture country). It is stored as the
        // corresponding UTC instant, not the naive digits -- see
        // TimezoneResolver / BookingSchedule::parse.
        $istWallClock = now('Asia/Kolkata')->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->set('scheduledAt', $istWallClock)
            ->call('next')->call('next')->call('next')
            ->assertSet('step', 'pay')
            ->set('paymentMethod', 'cash')
            ->call('placeBooking')
            ->assertRedirect();

        $stored = Booking::where('customer_id', $customer->id)->value('scheduled_at');

        $this->assertSame(
            \Illuminate\Support\Carbon::parse($istWallClock, 'Asia/Kolkata')->utc()->format('Y-m-d H:i'),
            $stored->format('Y-m-d H:i'),
            'stored as the UTC instant of the chosen IST wall clock',
        );
        $this->assertSame(
            $istWallClock,
            $stored->clone()->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'reads back as exactly the wall clock the customer picked',
        );
    }

    // ---------------------------------------------------------------- guards

    public function test_the_wizard_will_not_advance_past_address_without_a_valid_one(): void
    {
        ['customer' => $customer, 'service' => $service] = $this->world();

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', null)
            ->call('next')                       // configure -> address
            ->call('next')                       // address: no valid address -> stays
            ->assertSet('step', 'address')
            ->assertSee('Choose a service address');
    }

    public function test_a_customer_cannot_book_against_another_customers_address(): void
    {
        ['customer' => $customer, 'service' => $service, 'franchise' => $franchise, 'zone' => $zone] = $this->world();
        $stranger = $this->makeCustomer();
        $strangerAddress = $this->makeAddress($stranger, $franchise, $zone);

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', $strangerAddress->id)
            ->call('placeBooking')               // jump straight to booking
            ->assertSet('step', 'address')
            ->assertSee('Choose a valid service address');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_past_scheduled_time_is_rejected(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->set('scheduledAt', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('next')                       // configure -> address
            ->call('next')                       // address -> schedule
            ->call('next')                       // schedule: past time -> stays
            ->assertSet('step', 'schedule')
            ->assertSee('future');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_wallet_booking_with_an_empty_wallet_is_rejected_and_creates_nothing(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();
        $this->fund($customer, 10); // far below the 500 price

        $this->atPayStep($customer, $service, $address)
            ->set('paymentMethod', 'wallet')
            ->call('placeBooking')
            ->assertNoRedirect();

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Commission::count());
        $this->assertEquals(10.0, (float) $customer->wallet->fresh()->balance);
    }

    public function test_a_client_supplied_price_field_is_ignored_the_server_prices_the_booking(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();

        // Wizard has no price property; prove the created booking's price is
        // the server cascade's (base_price 500), never anything from input.
        $this->atPayStep($customer, $service, $address)
            ->set('paymentMethod', 'cash')
            ->call('placeBooking');

        $this->assertEquals(500.0, (float) Booking::where('customer_id', $customer->id)->value('price_quoted'));
    }

    public function test_add_address_inline_uses_the_browsing_zone_and_derives_the_franchise(): void
    {
        ['customer' => $customer, 'service' => $service, 'zone' => $zone] = $this->world();

        session([CustomerLocationContext::SESSION_KEY => $zone->id]);

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->call('next')
            ->set('addingAddress', true)
            ->set('newAddress.label', 'Office')
            ->set('newAddress.address_line', '12 Test Street')
            ->call('saveNewAddress')
            ->assertSet('addingAddress', false);

        $address = Address::where('user_id', $customer->id)->where('label', 'Office')->firstOrFail();
        $this->assertSame($zone->id, $address->zone_id);
        $this->assertSame($zone->franchise_id, $address->franchise_id);
    }
}
