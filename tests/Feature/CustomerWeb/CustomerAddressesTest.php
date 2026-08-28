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
