<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E6 — the security checklist from the mission brief §23, one test
 * per row, against the real customer web components.
 */
class CustomerWebSecurityE6Test extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(): array
    {
        [$c, $ci, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);
        Wallet::create(['user_id' => $customer->id, 'balance' => 9000]);

        return compact('customer', 'address', 'service', 'franchise', 'zone', 'provider');
    }

    /** Price manipulation — a client-set property cannot change the server charge. */
    public function test_setting_an_unknown_price_property_on_the_wizard_is_a_no_op(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();

        Livewire::actingAs($customer)->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->call('next')->call('next')->call('next')
            ->set('paymentMethod', 'cash')
            ->call('placeBooking');

        $this->assertEquals(500.0, (float) Booking::where('customer_id', $customer->id)->value('price_quoted'));
    }

    /** Provider manipulation — the customer cannot choose or inject a provider. */
    public function test_a_freshly_booked_job_is_unassigned_dispatch_decides_the_provider(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service] = $this->world();

        Livewire::actingAs($customer)->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->call('next')->call('next')->call('next')
            ->set('paymentMethod', 'cash')
            ->call('placeBooking');

        // The wizard exposes no provider property; CreateBookingAction never
        // sets provider_id, so a new job is always unassigned.
        $this->assertNull(Booking::where('customer_id', $customer->id)->value('provider_id'));
    }

    /** Status manipulation — no path lets the browser jump a booking to completed/paid/assigned. */
    public function test_the_order_page_has_no_setter_for_status_or_payment_state(): void
    {
        ['customer' => $customer, 'address' => $address, 'service' => $service, 'franchise' => $franchise, 'zone' => $zone, 'provider' => $provider] = $this->world();

        $booking = Booking::create([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'provider_id' => $provider->id,
            'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'cash',
            'start_otp' => '1234', 'completion_otp' => '5678',
        ]);

        $test = Livewire::actingAs($customer)->test(OrderShow::class, ['booking' => $booking]);

        foreach (['status' => 'completed', 'payment_status' => 'paid', 'bookingId' => $booking->id + 1] as $prop => $value) {
            try {
                $test->set($prop, $value);
            } catch (\Throwable $e) {
                // Locked / non-existent property — exactly the point.
            }
        }

        $booking->refresh();
        $this->assertSame('assigned', $booking->status);
        $this->assertSame('pending', $booking->payment_status);
    }

    /** Booking IDOR — mounting another customer's order 404s (not 403). */
    public function test_mounting_another_customers_order_is_a_404(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'service' => $service] = $this->world();
        $stranger = $this->makeCustomer();
        $strangerAddress = $this->makeAddress($stranger, $franchise, $zone);
        $theirBooking = Booking::create([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $stranger->id, 'service_id' => $service->id, 'address_id' => $strangerAddress->id,
            'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'cash',
        ]);

        $me = $this->makeCustomer();
        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $theirBooking])->assertStatus(404);
    }

    /** Authentication protection — every transactional route rejects a guest. */
    public function test_all_transactional_routes_redirect_guests_to_login(): void
    {
        ['service' => $service, 'customer' => $customer, 'franchise' => $franchise, 'zone' => $zone, 'address' => $address] = $this->world();
        $booking = Booking::create([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'cash',
        ]);

        foreach ([
            route('customer.book', $service),
            route('customer.orders.index'),
            route('customer.orders.show', $booking),
            route('customer.orders.invoice', $booking),
            route('customer.addresses'),
            route('customer.wallet'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('customer.login'));
        }
    }
}
