<?php

namespace Tests\Feature\Taxi;

use App\Contracts\Orderable;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\Payment;
use App\Models\TaxiRide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/** Phase 22.6 (Taxi). Migration/schema + relationship coverage, mirroring ParcelOrderSchemaTest. */
class TaxiRideSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use TaxiRideFixtureHelpers;

    public function test_taxi_ride_implements_orderable(): void
    {
        $scenario = $this->makeTaxiRideScenario();

        $this->assertInstanceOf(Orderable::class, $scenario['ride']);
        $this->assertSame('taxi', $scenario['ride']->moduleCode());
    }

    public function test_commission_can_belong_to_a_taxi_ride(): void
    {
        $scenario = $this->makeTaxiRideScenario();
        $commission = Commission::create(['taxi_ride_id' => $scenario['ride']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->assertNull($commission->fresh()->booking_id);
        $this->assertNull($commission->fresh()->parcel_order_id);
        $this->assertSame($scenario['ride']->id, $commission->taxiRide->id);
    }

    public function test_payment_purpose_accepts_taxi_ride_alongside_the_prior_four(): void
    {
        $scenario = $this->makeTaxiRideScenario();
        $payment = Payment::create(['taxi_ride_id' => $scenario['ride']->id, 'purpose' => 'taxi_ride', 'amount' => 80, 'status' => 'pending']);

        $this->assertSame('taxi_ride', $payment->fresh()->purpose);
        $this->assertSame($scenario['ride']->id, $payment->taxiRide->id);
    }

    public function test_dispatch_attempt_can_reference_a_taxi_ride_via_the_same_polymorphic_columns_parcel_uses(): void
    {
        $scenario = $this->makeTaxiRideScenario();

        $attempt = DispatchAttempt::create([
            'dispatchable_type' => TaxiRide::class, 'dispatchable_id' => $scenario['ride']->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $scenario['driver']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $this->assertInstanceOf(TaxiRide::class, $attempt->dispatchable);
        $this->assertInstanceOf(FieldWorker::class, $attempt->notifiable);
    }

    public function test_commissions_taxi_ride_id_is_unique(): void
    {
        $scenario = $this->makeTaxiRideScenario();
        Commission::create(['taxi_ride_id' => $scenario['ride']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('commissions')->insert(['taxi_ride_id' => $scenario['ride']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_all_three_verticals_can_coexist_with_independent_commission_rows(): void
    {
        $bookingScenario = $this->makeAssignedBookingScenario();
        $parcelScenario = $this->makeParcelOrderScenario();
        $taxiScenario = $this->makeTaxiRideScenario();

        Commission::create(['booking_id' => $bookingScenario['booking']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1]);
        Commission::create(['parcel_order_id' => $parcelScenario['order']->id, 'provider_commission' => 2, 'franchise_commission' => 2, 'platform_commission' => 2]);
        Commission::create(['taxi_ride_id' => $taxiScenario['ride']->id, 'provider_commission' => 3, 'franchise_commission' => 3, 'platform_commission' => 3]);

        $this->assertSame(3, Commission::count());
    }
}
