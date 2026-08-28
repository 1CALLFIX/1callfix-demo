<?php

namespace Tests\Feature\BookingBundle;

use App\Contracts\Orderable;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E1 (Multi-Service Booking — Data/Model Foundation). Proves the
 * additive `booking_bundles` wrapper: it persists, generates its own code
 * through the observer, wraps child `Booking` rows without changing them,
 * implements `Orderable` as a zero-behaviour delegation, and derives a
 * read-only cross-child status without ever touching the stored latch.
 *
 * Everything here is schema/model level only — there is no bundle creation
 * endpoint, pricing, payment or lifecycle behaviour to test yet (E2–E7).
 */
class BookingBundleModelTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private static int $franchiseCodeSeq = 0;

    /**
     * A franchise/zone/customer/address tree with a coded franchise (so the
     * observer's OrderCodeService call can run) plus a persisted bundle.
     * Reuses BookingFixtureHelpers rather than rebuilding fixture logic.
     */
    private function makeBundleScenario(string $status = 'active', array $overrides = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['code' => 'BDL' . str_pad((string) self::$franchiseCodeSeq++, 3, '0', STR_PAD_LEFT)]);
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $bundle = BookingBundle::create(array_merge([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'address_id' => $address->id,
            'status' => $status,
            'total_price_quoted' => 1500,
            'payment_status' => 'pending',
            'payment_method' => 'online',
        ], $overrides));

        return compact('country', 'city', 'franchise', 'zone', 'category', 'service', 'customer', 'address', 'bundle');
    }

    private function makeChildBooking(array $scenario, string $status): Booking
    {
        return Booking::create([
            'code' => 'TST-' . now()->format('dm') . '-' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'booking_bundle_id' => $scenario['bundle']->id,
            'franchise_id' => $scenario['franchise']->id,
            'zone_id' => $scenario['zone']->id,
            'customer_id' => $scenario['customer']->id,
            'service_id' => $scenario['service']->id,
            'address_id' => $scenario['address']->id,
            'status' => $status,
            'price_quoted' => 500,
            'payment_status' => 'pending',
            'payment_method' => 'online',
        ]);
    }

    // ── Test 1 ────────────────────────────────────────────────────────────

    public function test_a_booking_bundle_can_be_created_and_persisted(): void
    {
        $scenario = $this->makeBundleScenario();

        $this->assertTrue($scenario['bundle']->exists);
        $this->assertDatabaseHas('booking_bundles', [
            'id' => $scenario['bundle']->id,
            'status' => 'active',
            'payment_status' => 'pending',
            'payment_method' => 'online',
            'total_price_quoted' => 1500,
        ]);
        $this->assertNull($scenario['bundle']->fresh()->total_price_final);
    }

    // ── Test 2 ────────────────────────────────────────────────────────────

    public function test_a_bundle_code_is_generated_by_the_observer_with_the_bdl_pattern(): void
    {
        $scenario = $this->makeBundleScenario();
        $bundle = $scenario['bundle'];

        $this->assertNotEmpty($bundle->code);
        $this->assertStringStartsWith(strtoupper($scenario['franchise']->code) . '-BDL-', $bundle->code);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+-BDL-\d{4}-\d{8}$/', $bundle->code);

        // Second bundle for the SAME franchise on the same day -> next sequence number, still unique.
        $second = BookingBundle::create([
            'franchise_id' => $scenario['franchise']->id,
            'zone_id' => $scenario['zone']->id,
            'customer_id' => $scenario['customer']->id,
            'address_id' => $scenario['address']->id,
            'status' => 'active',
            'total_price_quoted' => 250,
        ]);

        $this->assertNotSame($bundle->code, $second->code);
        $this->assertSame(2, BookingBundle::where('code', 'like', strtoupper($scenario['franchise']->code) . '-BDL-%')->count());
    }

    public function test_a_preset_bundle_code_is_never_overwritten_by_the_observer(): void
    {
        $scenario = $this->makeBundleScenario(overrides: ['code' => 'FIXED-BDL-0101-00000042']);

        $this->assertSame('FIXED-BDL-0101-00000042', $scenario['bundle']->fresh()->code);
    }

    // ── Test 3 ────────────────────────────────────────────────────────────

    public function test_a_new_bundle_has_no_children(): void
    {
        $scenario = $this->makeBundleScenario();

        $this->assertSame(0, $scenario['bundle']->children()->count());
        $this->assertCount(0, $scenario['bundle']->fresh()->children);
    }

    // ── Test 4 ────────────────────────────────────────────────────────────

    public function test_a_booking_can_belong_to_a_bundle_and_both_sides_resolve(): void
    {
        $scenario = $this->makeBundleScenario();
        $booking = $this->makeChildBooking($scenario, 'pending');

        $this->assertSame($scenario['bundle']->id, $booking->fresh()->bundle->id);
        $this->assertTrue($scenario['bundle']->fresh()->children->contains($booking));
        $this->assertSame(1, $scenario['bundle']->children()->count());
    }

    // ── Test 5 ────────────────────────────────────────────────────────────

    public function test_derived_status_is_pending_when_every_child_is_pending(): void
    {
        $scenario = $this->makeBundleScenario();
        $this->makeChildBooking($scenario, 'pending');
        $this->makeChildBooking($scenario, 'searching_provider');

        $this->assertSame('pending', $scenario['bundle']->fresh()->derivedStatus());
    }

    public function test_derived_status_covers_representative_child_state_combinations(): void
    {
        // all completed
        $s1 = $this->makeBundleScenario();
        $this->makeChildBooking($s1, 'completed');
        $this->makeChildBooking($s1, 'completed');
        $this->assertSame('completed', $s1['bundle']->fresh()->derivedStatus());

        // all cancelled
        $s2 = $this->makeBundleScenario();
        $this->makeChildBooking($s2, 'cancelled');
        $this->makeChildBooking($s2, 'cancelled');
        $this->assertSame('cancelled', $s2['bundle']->fresh()->derivedStatus());

        // completed + cancelled, nothing outstanding
        $s3 = $this->makeBundleScenario();
        $this->makeChildBooking($s3, 'completed');
        $this->makeChildBooking($s3, 'cancelled');
        $this->assertSame('completed', $s3['bundle']->fresh()->derivedStatus());

        // work under way, nothing finished
        $s4 = $this->makeBundleScenario();
        $this->makeChildBooking($s4, 'assigned');
        $this->makeChildBooking($s4, 'pending');
        $this->assertSame('in_progress', $s4['bundle']->fresh()->derivedStatus());

        // some finished, others still outstanding
        $s5 = $this->makeBundleScenario();
        $this->makeChildBooking($s5, 'completed');
        $this->makeChildBooking($s5, 'in_progress');
        $this->assertSame('partially_completed', $s5['bundle']->fresh()->derivedStatus());

        // no children -> falls back to the stored latch
        $s6 = $this->makeBundleScenario('active');
        $this->assertSame('active', $s6['bundle']->fresh()->derivedStatus());
    }

    // ── Test 6 ────────────────────────────────────────────────────────────

    public function test_order_status_returns_the_stored_latch_not_the_derived_status(): void
    {
        $scenario = $this->makeBundleScenario('active');
        // Children all completed -> derivedStatus() would say 'completed'...
        $this->makeChildBooking($scenario, 'completed');
        $this->makeChildBooking($scenario, 'completed');
        $bundle = $scenario['bundle']->fresh();

        $this->assertSame('completed', $bundle->derivedStatus());
        // ...but the Orderable contract still reports the untouched stored latch.
        $this->assertSame('active', $bundle->orderStatus());
        $this->assertSame($bundle->status, $bundle->orderStatus());
    }

    // ── Test 7 ────────────────────────────────────────────────────────────

    public function test_order_total_price_prefers_final_then_falls_back_to_quoted(): void
    {
        $scenario = $this->makeBundleScenario();
        $bundle = $scenario['bundle'];

        $this->assertSame((float) $bundle->total_price_quoted, $bundle->orderTotalPrice());

        $bundle->update(['total_price_final' => 1234.50]);
        $this->assertSame(1234.50, $bundle->fresh()->orderTotalPrice());
    }

    // ── Orderable conformance ────────────────────────────────────────────

    public function test_bundle_implements_orderable_and_every_method_delegates_to_a_column(): void
    {
        $scenario = $this->makeBundleScenario();
        $bundle = $scenario['bundle'];

        $this->assertInstanceOf(Orderable::class, $bundle);
        $this->assertSame('service', $bundle->moduleCode());
        $this->assertSame($bundle->code, $bundle->orderCode());
        $this->assertSame($bundle->franchise_id, $bundle->orderFranchiseId());
        $this->assertSame($bundle->zone_id, $bundle->orderZoneId());
        $this->assertSame($bundle->customer_id, $bundle->orderCustomerId());
        $this->assertSame((float) $bundle->total_price_quoted, $bundle->orderTotalPrice());
        $this->assertSame($bundle->status, $bundle->orderStatus());
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function test_bundle_belongs_to_franchise_zone_customer_and_address(): void
    {
        $scenario = $this->makeBundleScenario();
        $bundle = $scenario['bundle']->fresh();

        $this->assertSame($scenario['franchise']->id, $bundle->franchise->id);
        $this->assertSame($scenario['zone']->id, $bundle->zone->id);
        $this->assertSame($scenario['customer']->id, $bundle->customer->id);
        $this->assertSame($scenario['address']->id, $bundle->address->id);
    }

    public function test_a_payment_can_belong_to_a_bundle_without_touching_child_payment_behaviour(): void
    {
        $scenario = $this->makeBundleScenario();

        $payment = Payment::create([
            'booking_bundle_id' => $scenario['bundle']->id,
            'purpose' => 'booking_bundle',
            'amount' => 1500,
            'status' => 'pending',
        ]);

        $this->assertSame('booking_bundle', $payment->fresh()->purpose);
        $this->assertNull($payment->fresh()->booking_id);
        $this->assertSame($scenario['bundle']->id, $payment->bookingBundle->id);
        $this->assertSame($payment->id, $scenario['bundle']->fresh()->payment->id);
    }

    // ── Legacy compatibility ────────────────────────────────────────────

    public function test_existing_single_service_bookings_are_unaffected(): void
    {
        $legacy = $this->makeBookingScenario();

        $this->assertNull($legacy['booking']->fresh()->booking_bundle_id);
        $this->assertNull($legacy['booking']->fresh()->bundle);
    }

    // ── Migration / schema shape ────────────────────────────────────────

    public function test_booking_bundles_table_has_the_expected_columns(): void
    {
        foreach ([
            'id', 'code', 'franchise_id', 'zone_id', 'customer_id', 'address_id', 'status',
            'payment_status', 'payment_method', 'total_price_quoted', 'total_price_final',
            'cancellation_note', 'cancellation_fee', 'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('booking_bundles', $column), "booking_bundles is missing `$column`");
        }
    }

    public function test_booking_bundle_id_is_nullable_on_bookings_and_payments(): void
    {
        $this->assertTrue(Schema::hasColumn('bookings', 'booking_bundle_id'));
        $this->assertTrue(Schema::hasColumn('payments', 'booking_bundle_id'));

        // A booking with no bundle still saves (nullable, no backfill).
        $legacy = $this->makeBookingScenario();
        $this->assertNull($legacy['booking']->fresh()->booking_bundle_id);

        // A payment with no bundle still saves.
        $payment = Payment::create(['purpose' => 'booking', 'amount' => 10, 'status' => 'pending']);
        $this->assertNull($payment->fresh()->booking_bundle_id);
    }

    public function test_payment_purpose_still_accepts_every_prior_value_plus_booking_bundle(): void
    {
        $purposes = [
            'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride',
            'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation',
            'booking_bundle',
        ];

        foreach ($purposes as $purpose) {
            $payment = Payment::create(['purpose' => $purpose, 'amount' => 10, 'status' => 'pending']);
            $this->assertSame($purpose, $payment->fresh()->purpose, "purpose `$purpose` was not stored");
        }
    }

    // ── Soft deletes ────────────────────────────────────────────────────

    public function test_bundle_soft_deletes_like_other_order_models(): void
    {
        $scenario = $this->makeBundleScenario();
        $id = $scenario['bundle']->id;

        $scenario['bundle']->delete();

        $this->assertSoftDeleted('booking_bundles', ['id' => $id]);
        $this->assertNull(BookingBundle::find($id));
        $this->assertNotNull(BookingBundle::withTrashed()->find($id));
    }
}
