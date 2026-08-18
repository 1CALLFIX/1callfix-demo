<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * P0 Customer Core API — Response contract (mission item 7). Proves the one
 * consistent envelope (`ApiResponse`) across success/validation-error/
 * authorization-error/not-found/pagination for the new Customer Core
 * endpoints, without touching or asserting anything about pre-existing
 * endpoints' (deliberately untouched) shapes.
 */
class CustomerApiResponseContractTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_success_envelope_has_success_and_data_keys(): void
    {
        $this->makeCategoryAndService();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_validation_error_envelope_has_success_false_message_and_errors(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/addresses', [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_authentication_error_returns_401(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_not_found_envelope_has_success_false_and_message(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/addresses/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_pagination_meta_is_present_on_a_list_endpoint(): void
    {
        $scenario = $this->makeBookingScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->getJson('/api/bookings/mine')
            ->assertOk()
            ->assertJsonStructure(['success', 'data', 'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);
    }
}
