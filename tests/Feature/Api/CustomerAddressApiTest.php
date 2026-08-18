<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** P0 Customer Core API — Customer addresses CRUD (mission item 6). */
class CustomerAddressApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_addresses_require_authentication(): void
    {
        $this->getJson('/api/addresses')->assertStatus(401);
    }

    public function test_customer_can_create_an_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/addresses', [
                'zone_id' => $zone->id, 'lat' => 12.34, 'lng' => 56.78,
                'address_line' => '123 Main St', 'label' => 'Work',
            ])
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        $address = Address::first();
        $this->assertSame($customer->id, $address->user_id);
        $this->assertSame($franchise->id, $address->franchise_id, 'franchise_id must be derived from the zone, not client-supplied.');
        $response->assertJsonPath('data.id', $address->id);
    }

    public function test_a_client_supplied_franchise_id_is_ignored_in_favor_of_the_zones_real_franchise(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/addresses', [
                'zone_id' => $zone->id, 'lat' => 1, 'lng' => 1, 'address_line' => 'Addr',
                'franchise_id' => $otherFranchise->id,
            ])
            ->assertStatus(201);

        $this->assertSame($franchise->id, Address::first()->franchise_id);
    }

    public function test_address_creation_validates_required_fields(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/addresses', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zone_id', 'lat', 'lng', 'address_line']);
    }

    public function test_customer_sees_only_their_own_addresses(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $mine = $this->makeAddress($customer, $franchise, $zone);
        $this->makeAddress($other, $franchise, $zone);

        $response = $this->actingAs($customer, 'sanctum')->getJson('/api/addresses')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_customer_can_view_their_own_address_detail(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/addresses/{$address->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $address->id);
    }

    public function test_a_customer_cannot_view_another_customers_address_idor(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $othersAddress = $this->makeAddress($other, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/addresses/{$othersAddress->id}")
            ->assertStatus(404);
    }

    public function test_customer_can_update_their_own_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->putJson("/api/addresses/{$address->id}", ['address_line' => 'Updated Line', 'is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.address_line', 'Updated Line')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_a_customer_cannot_update_another_customers_address_idor(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $othersAddress = $this->makeAddress($other, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->putJson("/api/addresses/{$othersAddress->id}", ['address_line' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertNotSame('Hijacked', $othersAddress->fresh()->address_line);
    }

    public function test_customer_can_delete_their_own_unused_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/addresses/{$address->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull(Address::find($address->id));
    }

    public function test_a_customer_cannot_delete_another_customers_address_idor(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $othersAddress = $this->makeAddress($other, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/addresses/{$othersAddress->id}")
            ->assertStatus(404);

        $this->assertNotNull(Address::find($othersAddress->id));
    }

    public function test_an_address_referenced_by_a_booking_cannot_be_deleted(): void
    {
        // Real schema hazard this endpoint guards against: bookings.address_id
        // is cascadeOnDelete() -- an unguarded delete would silently destroy
        // the booking too.
        $scenario = $this->makeBookingScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->deleteJson("/api/addresses/{$scenario['address']->id}")
            ->assertStatus(409);

        $this->assertNotNull(Address::find($scenario['address']->id));
        $this->assertNotNull($scenario['booking']->fresh());
    }
}
