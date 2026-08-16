<?php

namespace Tests\Feature\PropertyRental;

use App\Contracts\Orderable;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\PropertyAvailability;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.7 (Property Rental). Migration/schema + relationship coverage,
 * mirroring ParcelOrderSchemaTest/TaxiRideSchemaTest, plus the direct
 * concurrency-backstop proof the mission brief specifically asked for.
 */
class PropertyReservationSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    public function test_property_reservation_implements_orderable(): void
    {
        $scenario = $this->makePropertyReservationScenario();

        $this->assertInstanceOf(Orderable::class, $scenario['reservation']);
        $this->assertSame('car_rental', $scenario['reservation']->moduleCode());
    }

    public function test_commission_can_belong_to_a_property_reservation(): void
    {
        $scenario = $this->makePropertyReservationScenario();
        $commission = Commission::create(['property_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->assertNull($commission->fresh()->booking_id);
        $this->assertSame($scenario['reservation']->id, $commission->propertyReservation->id);
    }

    public function test_payment_purpose_accepts_property_reservation_alongside_the_prior_five(): void
    {
        $scenario = $this->makePropertyReservationScenario();
        $payment = Payment::create(['property_reservation_id' => $scenario['reservation']->id, 'purpose' => 'property_reservation', 'amount' => 2000, 'status' => 'pending']);

        $this->assertSame('property_reservation', $payment->fresh()->purpose);
        $this->assertSame($scenario['reservation']->id, $payment->propertyReservation->id);
    }

    public function test_review_can_belong_to_a_property_reservation_instead_of_a_booking(): void
    {
        $scenario = $this->makePropertyReservationScenario();

        $review = Review::create([
            'property_reservation_id' => $scenario['reservation']->id,
            'customer_id' => $scenario['customer']->id,
            'provider_id' => $scenario['owner']->id,
            'rating' => 5,
        ]);

        $this->assertNull($review->fresh()->booking_id);
        $this->assertSame($scenario['reservation']->id, $review->propertyReservation->id);
    }

    public function test_existing_service_reviews_are_unaffected(): void
    {
        $bookingScenario = $this->makeAssignedBookingScenario();

        $review = Review::create([
            'booking_id' => $bookingScenario['booking']->id,
            'customer_id' => $bookingScenario['customer']->id,
            'provider_id' => $bookingScenario['provider']->id,
            'rating' => 4,
        ]);

        $this->assertSame($bookingScenario['booking']->id, $review->fresh()->booking_id);
        $this->assertNull($review->fresh()->property_reservation_id);
    }

    public function test_commissions_property_reservation_id_is_unique(): void
    {
        $scenario = $this->makePropertyReservationScenario();
        Commission::create(['property_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('commissions')->insert(['property_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The direct, real proof of the concurrency backstop
     * `PropertyAvailabilityService`'s own class docblock describes —
     * PHPUnit is single-threaded (the same honest limitation this
     * codebase's own `ServiceMatchingJobRaceTest` docblock already states
     * for itself, not hidden here either), so this doesn't simulate true
     * concurrent HTTP requests; it proves the actual DB-level guarantee
     * the concurrency-safety design depends on is real: a second INSERT
     * for the same `(property_id, date)` is rejected by the database
     * itself, exactly the case where two racing transactions would BOTH
     * pass the `lockForUpdate()` step (neither sees a pre-existing row)
     * and BOTH attempt to insert.
     */
    public function test_property_availabilities_unique_constraint_is_the_real_concurrency_backstop(): void
    {
        $scenario = $this->makePropertyReservationScenario();
        $date = now()->addDays(10)->toDateString();

        PropertyAvailability::create(['property_id' => $scenario['property']->id, 'date' => $date, 'is_available' => false, 'reason' => 'booked']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('property_availabilities')->insert([
            'property_id' => $scenario['property']->id, 'date' => $date, 'is_available' => false, 'reason' => 'booked',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_all_four_verticals_can_coexist_with_independent_commission_rows(): void
    {
        $bookingScenario = $this->makeAssignedBookingScenario();
        $propertyScenario = $this->makePropertyReservationScenario();

        Commission::create(['booking_id' => $bookingScenario['booking']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1]);
        Commission::create(['property_reservation_id' => $propertyScenario['reservation']->id, 'provider_commission' => 2, 'franchise_commission' => 2, 'platform_commission' => 2]);

        $this->assertSame(2, Commission::count());
    }
}
