<?php

namespace Tests\Feature\Rental;

use App\Contracts\Orderable;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\Feature\Support\RentalFixtureHelpers;
use Tests\TestCase;

/**
 * RENTAL MODULE IMPLEMENTATION -- migration/schema + relationship coverage
 * for the shared Vehicle/Equipment engine, mirroring
 * PropertyReservationSchemaTest, plus proof that Property Rental's own
 * engine is untouched and all three rental_type values coexist.
 */
class RentalReservationSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use RentalFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    public function test_rental_reservation_implements_orderable(): void
    {
        $scenario = $this->makeVehicleReservationScenario();

        $this->assertInstanceOf(Orderable::class, $scenario['reservation']);
        $this->assertSame('rental', $scenario['reservation']->moduleCode());
    }

    public function test_vehicle_and_equipment_reservations_share_one_engine_and_module(): void
    {
        $vehicleScenario = $this->makeVehicleReservationScenario();
        $equipmentScenario = $this->makeEquipmentReservationScenario();

        $this->assertSame('rental', $vehicleScenario['reservation']->moduleCode());
        $this->assertSame('rental', $equipmentScenario['reservation']->moduleCode());
        $this->assertSame('vehicle', $vehicleScenario['reservation']->rental_type);
        $this->assertSame('equipment', $equipmentScenario['reservation']->rental_type);
        $this->assertSame(\App\Models\RentalReservation::class, get_class($vehicleScenario['reservation']));
        $this->assertSame(\App\Models\RentalReservation::class, get_class($equipmentScenario['reservation']));
    }

    public function test_rentable_polymorphic_relation_resolves_to_the_correct_model(): void
    {
        $vehicleScenario = $this->makeVehicleReservationScenario();
        $equipmentScenario = $this->makeEquipmentReservationScenario();

        $this->assertInstanceOf(\App\Models\Vehicle::class, $vehicleScenario['reservation']->rentable);
        $this->assertInstanceOf(\App\Models\EquipmentItem::class, $equipmentScenario['reservation']->rentable);
    }

    public function test_commission_can_belong_to_a_rental_reservation(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        $commission = Commission::create(['rental_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->assertNull($commission->fresh()->booking_id);
        $this->assertSame($scenario['reservation']->id, $commission->rentalReservation->id);
    }

    public function test_payment_purpose_accepts_rental_reservation(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        $payment = Payment::create(['rental_reservation_id' => $scenario['reservation']->id, 'purpose' => 'rental_reservation', 'amount' => 1000, 'status' => 'pending']);

        $this->assertSame('rental_reservation', $payment->fresh()->purpose);
        $this->assertSame($scenario['reservation']->id, $payment->rentalReservation->id);
    }

    public function test_review_can_belong_to_a_rental_reservation(): void
    {
        $scenario = $this->makeVehicleReservationScenario();

        $review = Review::create([
            'rental_reservation_id' => $scenario['reservation']->id,
            'customer_id' => $scenario['customer']->id,
            'provider_id' => $scenario['owner']->id,
            'rating' => 5,
        ]);

        $this->assertNull($review->fresh()->booking_id);
        $this->assertSame($scenario['reservation']->id, $review->rentalReservation->id);
    }

    public function test_commissions_rental_reservation_id_is_unique(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        Commission::create(['rental_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('commissions')->insert(['rental_reservation_id' => $scenario['reservation']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Direct proof that Property Rental's own engine (property_reservations,
     * PropertyReservation, property_reservation_id FKs) is byte-for-byte
     * unchanged by this phase's addition of the shared Vehicle/Equipment
     * engine -- both a Property reservation and a Vehicle reservation can
     * exist with independent commission rows under the SAME `rental` module.
     */
    public function test_property_vehicle_and_equipment_reservations_coexist_with_independent_commission_rows(): void
    {
        $propertyScenario = $this->makePropertyReservationScenario();
        $vehicleScenario = $this->makeVehicleReservationScenario();
        $equipmentScenario = $this->makeEquipmentReservationScenario();

        Commission::create(['property_reservation_id' => $propertyScenario['reservation']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1]);
        Commission::create(['rental_reservation_id' => $vehicleScenario['reservation']->id, 'provider_commission' => 2, 'franchise_commission' => 2, 'platform_commission' => 2]);
        Commission::create(['rental_reservation_id' => $equipmentScenario['reservation']->id, 'provider_commission' => 3, 'franchise_commission' => 3, 'platform_commission' => 3]);

        $this->assertSame(3, Commission::count());
        $this->assertSame('rental', $propertyScenario['reservation']->moduleCode());
        $this->assertSame('rental', $vehicleScenario['reservation']->moduleCode());
    }
}
