<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) finding: routes/api.php
 * had no general-purpose rate limiter at all -- only the 6 auth/OTP/QR
 * routes had their own explicit throttle. Every other authenticated route
 * (wallet top-up, loyalty redeem, payment order creation, etc.) could be
 * called at unlimited volume by any valid Sanctum token. Fixed via
 * AppServiceProvider's new 'api' RateLimiter (Laravel's own standard
 * 60/min-per-user default) + bootstrap/app.php's throttleApi(). This test
 * proves the limiter is actually wired onto a real, previously-unthrottled
 * route -- not just present in config with nothing consuming it.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_a_previously_unthrottled_route_now_returns_429_past_60_requests_per_minute(): void
    {
        $customer = $this->makeCustomer();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($customer, 'sanctum')->getJson('/api/wallet')->assertOk();
        }

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/wallet')
            ->assertStatus(429);
    }

    public function test_the_limit_is_scoped_per_user_not_global(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($customerA, 'sanctum')->getJson('/api/wallet')->assertOk();
        }
        $this->actingAs($customerA, 'sanctum')->getJson('/api/wallet')->assertStatus(429);

        // A completely different authenticated user must have their own,
        // untouched allowance -- the limiter keys per user id, not per IP
        // or globally, for authenticated requests.
        $this->actingAs($customerB, 'sanctum')->getJson('/api/wallet')->assertOk();
    }
}
