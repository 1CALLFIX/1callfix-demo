<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Account\Addresses;
use App\Models\Address;
use App\Models\Booking;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E6 — saved addresses. Same rules as API\AddressController:
 * user_id server-set, franchise derived from the browsing zone, and the
 * delete-guard that stops a cascadeOnDelete() FK from taking a booking with
 * the address.
 */
class CustomerAddressesTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('customer.addresses'))->assertRedirect(route('customer.login'));
    }

    // ---------- Phase 3: "Use my current location" on the add form ----------

    public function test_use_current_location_pins_a_new_address_to_the_zone_covering_those_coordinates(): void
    {
        [, , , $headerZone] = $this->makeFranchiseTree();
        [, , $geoFranchise, $geoZone] = $this->makeFranchiseTree();
        $geoZone->update(['center_lat' => 19.0760, 'center_lng' => 72.8777, 'default_dispatch_radius_km' => 10]);
        $customer = $this->makeCustomer();
        session([CustomerLocationContext::SESSION_KEY => $headerZone->id]);

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('startAdd')
            ->set('form.label', 'Site')
            ->set('form.address_line', '5 Marine Drive')
            ->call('useCurrentLocationForNewAddress', 19.0800, 72.8777)
            ->assertSet('locatedZoneId', $geoZone->id)
            ->assertSet('locatedZoneName', $geoZone->name)
            ->call('save')
            ->assertHasNoErrors();

        $address = Address::where('user_id', $customer->id)->latest('id')->first();
        $this->assertSame($geoZone->id, $address->zone_id);           // geo zone, not the browsing zone
        $this->assertSame($geoFranchise->id, $address->franchise_id);
        $this->assertEqualsWithDelta(19.0800, (float) $address->lat, 0.0001);
        $this->assertEqualsWithDelta(72.8777, (float) $address->lng, 0.0001);
    }

    public function test_use_current_location_outside_coverage_reports_it_and_pins_nothing(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 12.97, 'center_lng' => 77.59, 'default_dispatch_radius_km' => 8]);
        $customer = $this->makeCustomer();
        session([CustomerLocationContext::SESSION_KEY => $zone->id]);

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('startAdd')
            ->call('useCurrentLocationForNewAddress', 51.5072, -0.1276)
            ->assertSet('locatedZoneId', null)
            ->assertSet('error', "We don't have a team serving that exact spot yet — type the address and pick your area from the top of the page.");
    }

    public function test_use_current_location_is_a_noop_while_editing_an_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 19.0760, 'center_lng' => 72.8777, 'default_dispatch_radius_km' => 10]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('edit', $address->id)
            ->call('useCurrentLocationForNewAddress', 19.0800, 72.8777)
            ->assertSet('locatedZoneId', null);
    }

    public function test_adding_an_address_derives_the_franchise_from_the_browsing_zone(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        session([CustomerLocationContext::SESSION_KEY => $zone->id]);

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('startAdd')
            ->set('form.label', 'Home')
            ->set('form.address_line', '5 Test Lane')
            ->call('save')
            ->assertSee('Address added');

        $address = Address::where('user_id', $customer->id)->firstOrFail();
        $this->assertSame($zone->id, $address->zone_id);
        $this->assertSame($zone->franchise_id, $address->franchise_id);
    }

    public function test_adding_an_address_without_a_chosen_area_is_refused(): void
    {
        $customer = $this->makeCustomer();

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('startAdd')
            ->set('form.label', 'Home')
            ->set('form.address_line', '5 Test Lane')
            ->call('save')
            ->assertSee('Choose your area');

        $this->assertSame(0, Address::where('user_id', $customer->id)->count());
    }

    public function test_a_customer_cannot_edit_or_delete_another_customers_address(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $me = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $strangerAddress = $this->makeAddress($stranger, $franchise, $zone);

        Livewire::actingAs($me)->test(Addresses::class)
            ->call('edit', $strangerAddress->id)
            ->assertStatus(404);

        Livewire::actingAs($me)->test(Addresses::class)
            ->call('delete', $strangerAddress->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('addresses', ['id' => $strangerAddress->id]);
    }

    public function test_an_address_in_use_by_a_booking_cannot_be_deleted(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $service = $this->makeService($this->makeCategory(['module' => 'service']));

        Booking::create([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'searching_provider', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'cash',
        ]);

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('delete', $address->id)
            ->assertSee("can't be deleted");

        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }

    public function test_an_unused_address_can_be_deleted(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        Livewire::actingAs($customer)->test(Addresses::class)
            ->call('delete', $address->id)
            ->assertSee('Address removed');

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }
}
