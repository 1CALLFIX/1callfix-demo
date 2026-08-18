<?php

namespace Tests\Feature\Api;

use App\Models\Booking;
use App\Services\ModuleActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * P0 Customer Core API — Customer Service booking create/history/detail/
 * cancellation (mission items 2/3/4).
 */
class CustomerBookingApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    // ============================== create ==============================

    public function test_booking_creation_requires_authentication(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id])
            ->assertStatus(401);
    }

    public function test_customer_can_create_a_booking_through_the_real_action(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'payment_method' => 'cash',
                'customer_note' => 'Please call before arriving.',
            ])
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame($franchise->id, $booking->franchise_id);
        $this->assertSame($zone->id, $booking->zone_id);
        // ServiceMatchingJob is dispatched synchronously in tests (queue=sync)
        // and immediately advances status past 'pending' -- this is real
        // CreateBookingAction/ServiceMatchingJob behavior, not a bug.
        $this->assertContains($booking->status, ['pending', 'searching_provider']);
        $this->assertEquals(500, $booking->price_quoted); // Service::resolvePrice() with no franchise override -> base_price.
        $response->assertJsonPath('data.id', $booking->id);
    }

    public function test_client_supplied_price_is_ignored_server_side_pricing_always_wins(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'price_quoted' => 1, // must be silently ignored -- StoreBookingRequest doesn't even accept it.
            ])
            ->assertStatus(201);

        $this->assertEquals(500, Booking::first()->price_quoted);
    }

    public function test_client_supplied_customer_id_and_franchise_zone_are_ignored(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'customer_id' => $otherCustomer->id,
                'franchise_id' => $otherFranchise->id,
                'zone_id' => $otherZone->id,
            ])
            ->assertStatus(201);

        $booking = Booking::first();
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame($franchise->id, $booking->franchise_id);
        $this->assertSame($zone->id, $booking->zone_id);
    }

    public function test_a_customer_cannot_book_using_another_customers_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer();
        $othersAddress = $this->makeAddress($otherCustomer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $othersAddress->id])
            ->assertStatus(404);

        $this->assertSame(0, Booking::count());
    }

    public function test_booking_creation_fails_for_an_inactive_service(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $service->update(['is_active' => false]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id])
            ->assertStatus(404);
    }

    public function test_booking_creation_validation_requires_service_and_address(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['service_id', 'address_id']);
    }

    public function test_booking_creation_is_blocked_while_the_service_module_is_disabled_for_the_franchise(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        app(ModuleActivationService::class)->setActive('service', 'franchise', $franchise->id, false);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, Booking::count());
    }

    public function test_wallet_payment_debits_the_wallet_through_the_real_action(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        \App\Models\Wallet::create(['user_id' => $customer->id, 'balance' => 1000]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'wallet'])
            ->assertStatus(201);

        $booking = Booking::first();
        $this->assertSame('paid', $booking->payment_status);
        $this->assertEquals(500, $customer->wallet->fresh()->balance);
    }

    public function test_wallet_payment_fails_with_insufficient_balance(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        \App\Models\Wallet::create(['user_id' => $customer->id, 'balance' => 1]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', ['service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'wallet'])
            ->assertStatus(409);

        $this->assertSame(0, Booking::count());
    }

    // ============================== mine / show ==============================

    public function test_mine_returns_only_the_callers_own_bookings_paginated(): void
    {
        $mine = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();

        $response = $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/bookings/mine')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine['booking']->id, $response->json('data.0.id'));
        $this->assertArrayHasKey('pagination', $response->json('meta'));
    }

    public function test_mine_returns_an_empty_list_for_a_customer_with_no_bookings(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/bookings/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mine_paginates(): void
    {
        $customer = $this->makeCustomer();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $address = $this->makeAddress($customer, $franchise, $zone);

        for ($i = 0; $i < 3; $i++) {
            Booking::create([
                'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
                'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
                'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
                'status' => 'pending', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
            ]);
        }

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson('/api/bookings/mine?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.pagination.total'));
        $this->assertSame(2, $response->json('meta.pagination.last_page'));
    }

    public function test_show_returns_the_callers_own_booking_with_detail_fields(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->getJson("/api/bookings/{$scenario['booking']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $scenario['booking']->id)
            ->assertJsonPath('data.provider.name', $scenario['provider']->user->name)
            ->assertJsonMissingPath('data.provider.kyc_status');
    }

    public function test_a_customer_cannot_view_another_customers_booking(): void
    {
        $scenario = $this->makeBookingScenario();
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/bookings/{$scenario['booking']->id}")
            ->assertStatus(404);
    }

    // ============================== cancel ==============================

    public function test_customer_can_cancel_their_own_pending_booking(): void
    {
        $scenario = $this->makeBookingScenario('pending');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $scenario['booking']->fresh()->status);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $scenario = $this->makeBookingScenario('pending');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_a_customer_cannot_cancel_another_customers_booking(): void
    {
        $scenario = $this->makeBookingScenario('pending');
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/cancel", ['reason' => 'Not mine'])
            ->assertStatus(404);

        $this->assertSame('pending', $scenario['booking']->fresh()->status);
    }

    public function test_an_already_completed_booking_cannot_be_cancelled(): void
    {
        $scenario = $this->makeBookingScenario('completed');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/cancel", ['reason' => 'Too late'])
            ->assertStatus(409);

        $this->assertSame('completed', $scenario['booking']->fresh()->status);
    }

    public function test_cancellation_fee_and_refund_behavior_is_honored_via_the_real_cancellation_service(): void
    {
        $scenario = $this->makeBookingScenario('pending');
        \App\Models\Setting::set('cancellation.free_minutes', '0');
        \App\Models\Setting::set('cancellation.fee_type', 'flat');
        \App\Models\Setting::set('cancellation.fee_value', '50');
        $scenario['booking']->update(['payment_status' => 'paid']);
        \App\Models\Payment::create([
            'booking_id' => $scenario['booking']->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
        ]);
        \App\Models\Wallet::create(['user_id' => $scenario['customer']->id, 'balance' => 0]);

        $response = $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/cancel", ['reason' => 'Fee test'])
            ->assertOk();

        $this->assertEquals(50, $response->json('data.cancellation_fee'));
        // Refund = 500 - 50 = 450, credited to the wallet via the real WalletService.
        $this->assertEquals(450, $scenario['customer']->wallet->fresh()->balance);
    }
}
