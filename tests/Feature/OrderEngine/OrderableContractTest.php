<?php

namespace Tests\Feature\OrderEngine;

use App\Contracts\Orderable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.2 (Order Engine Architecture Decision). Proves the one real
 * change this phase made to existing code -- Booking now implements
 * Orderable -- is genuinely zero-behavior-change: every method just
 * delegates to a column that already existed.
 */
class OrderableContractTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_booking_implements_orderable(): void
    {
        $scenario = $this->makeBookingScenario();

        $this->assertInstanceOf(Orderable::class, $scenario['booking']);
    }

    public function test_orderable_methods_delegate_to_existing_columns(): void
    {
        $scenario = $this->makeBookingScenario();
        $booking = $scenario['booking'];

        $this->assertSame('service', $booking->moduleCode());
        $this->assertSame($booking->code, $booking->orderCode());
        $this->assertSame($booking->franchise_id, $booking->orderFranchiseId());
        $this->assertSame($booking->zone_id, $booking->orderZoneId());
        $this->assertSame($booking->customer_id, $booking->orderCustomerId());
        $this->assertSame((float) $booking->price_quoted, $booking->orderTotalPrice());
        $this->assertSame($booking->status, $booking->orderStatus());
    }

    public function test_order_total_price_prefers_price_final_once_set(): void
    {
        $scenario = $this->makeBookingScenario();
        $booking = $scenario['booking'];
        $booking->update(['price_final' => 999.50]);

        $this->assertSame(999.50, $booking->fresh()->orderTotalPrice());
    }
}
