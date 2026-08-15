<?php

namespace Tests\Feature\Api;

use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) — GET /api/wallet
 * (WalletController::show) had zero HTTP-level coverage before this file;
 * /api/wallet/topup was already tested (PaymentGatewayTest) but the read
 * side never was. Confirms the balance shown is genuinely per-caller (a
 * second customer's wallet never leaks into the first's response) even
 * though the endpoint takes no id at all — the only IDOR surface here is
 * "does it use $request->user() or something else."
 */
class WalletApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();
    }

    public function test_returns_the_callers_own_balance(): void
    {
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 250, reason: 'test credit', ref: 'test:'.$customer->id);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJson(['balance' => 250]);
    }

    public function test_a_second_customers_balance_never_leaks_into_the_first_callers_response(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        app(WalletService::class)->credit($customerA, 100, reason: 'test', ref: 'a:'.$customerA->id);
        app(WalletService::class)->credit($customerB, 999, reason: 'test', ref: 'b:'.$customerB->id);

        $this->actingAs($customerA, 'sanctum')
            ->getJson('/api/wallet')
            ->assertOk()
            ->assertJson(['balance' => 100]);
    }
}
