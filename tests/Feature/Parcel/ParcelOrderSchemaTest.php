<?php

namespace Tests\Feature\Parcel;

use App\Contracts\Orderable;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.4 (Parcel). Migration/schema + model-relationship coverage —
 * proves the additive migrations actually did what they claim (nullable
 * FKs, real relations resolve, existing Booking-linked rows are
 * unaffected) rather than trusting the migration files' own comments.
 */
class ParcelOrderSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    public function test_parcel_order_implements_orderable(): void
    {
        $scenario = $this->makeParcelOrderScenario();

        $this->assertInstanceOf(Orderable::class, $scenario['order']);
        $this->assertSame('parcel', $scenario['order']->moduleCode());
        $this->assertSame($scenario['order']->code, $scenario['order']->orderCode());
    }

    public function test_commission_booking_id_is_now_nullable_and_existing_service_rows_are_unaffected(): void
    {
        $bookingScenario = $this->makeAssignedBookingScenario();
        $commission = Commission::create(['booking_id' => $bookingScenario['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->assertSame($bookingScenario['booking']->id, $commission->fresh()->booking_id);
        $this->assertNull($commission->fresh()->parcel_order_id);
        $this->assertSame($bookingScenario['booking']->code, $commission->booking->code);
    }

    public function test_commission_can_now_belong_to_a_parcel_order_instead_of_a_booking(): void
    {
        $scenario = $this->makeParcelOrderScenario();
        $commission = Commission::create(['parcel_order_id' => $scenario['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->assertNull($commission->fresh()->booking_id);
        $this->assertSame($scenario['order']->id, $commission->parcelOrder->id);
    }

    public function test_payment_purpose_accepts_parcel_order_alongside_the_original_three(): void
    {
        $scenario = $this->makeParcelOrderScenario();

        $payment = Payment::create(['parcel_order_id' => $scenario['order']->id, 'purpose' => 'parcel_order', 'amount' => 100, 'status' => 'pending']);

        $this->assertSame('parcel_order', $payment->fresh()->purpose);
        $this->assertSame($scenario['order']->id, $payment->parcelOrder->id);
    }

    public function test_dispatch_attempt_can_reference_a_parcel_order_via_the_new_polymorphic_columns(): void
    {
        $scenario = $this->makeParcelOrderScenario();

        $attempt = DispatchAttempt::create([
            'dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $scenario['rider']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $this->assertNull($attempt->fresh()->booking_id);
        $this->assertNull($attempt->fresh()->provider_id);
        $this->assertInstanceOf(ParcelOrder::class, $attempt->dispatchable);
        $this->assertInstanceOf(FieldWorker::class, $attempt->notifiable);
    }

    public function test_existing_service_dispatch_attempts_still_write_booking_id_and_provider_id_unchanged(): void
    {
        $bookingScenario = $this->makeBookingScenario();

        $attempt = DispatchAttempt::create([
            'booking_id' => $bookingScenario['booking']->id, 'provider_id' => $bookingScenario['provider']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $this->assertSame($bookingScenario['booking']->id, $attempt->fresh()->booking_id);
        $this->assertNull($attempt->fresh()->dispatchable_type);
        $this->assertNull($attempt->fresh()->notifiable_type);
    }

    public function test_commissions_parcel_order_id_is_unique(): void
    {
        $scenario = $this->makeParcelOrderScenario();
        Commission::create(['parcel_order_id' => $scenario['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('commissions')->insert(['parcel_order_id' => $scenario['order']->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_status_history_and_deleting_a_parcel_order_cascades_correctly(): void
    {
        $scenario = $this->makeParcelOrderScenario();
        $scenario['order']->statusHistory()->create(['status' => 'pending', 'changed_at' => now()]);

        $this->assertSame(1, $scenario['order']->statusHistory()->count());

        $scenario['order']->forceDelete();

        $this->assertSame(0, DB::table('parcel_order_status_history')->where('parcel_order_id', $scenario['order']->id)->count());
    }
}
