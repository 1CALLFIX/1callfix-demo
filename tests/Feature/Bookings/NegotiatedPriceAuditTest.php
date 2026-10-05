<?php

namespace Tests\Feature\Bookings;

use App\Actions\CreateBookingAction;
use App\Models\ActivityLog;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * C3 fixes item 6 — the admin negotiated price (`price_quoted` supplied by the call-centre form) is audited: who set
 * it, the catalogue price and the negotiated price. Log only; the booking is priced exactly as before.
 */
class NegotiatedPriceAuditTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Queue::fake();

        return compact('franchise', 'zone', 'service', 'customer', 'address');
    }

    private function payload(array $w, array $extra = []): array
    {
        return $extra + [
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online',
        ];
    }

    public function test_a_negotiated_price_logs_who_set_it_the_catalogue_price_and_the_negotiated_price(): void
    {
        $w = $this->world();
        $admin = $this->makeSuperAdmin();

        $booking = $this->actingAs($admin)->app->make(CreateBookingAction::class)->execute($this->payload($w, ['price_quoted' => 350]));

        $this->assertEquals(350.0, (float) $booking->price_quoted, 'Behaviour is unchanged: the negotiated price is honoured.');

        $log = ActivityLog::where('subject_type', Booking::class)->where('subject_id', $booking->id)->where('description', 'negotiated price set')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertEquals(500.0, (float) $log->properties['catalogue_price']);
        $this->assertEquals(350.0, (float) $log->properties['negotiated_price']);
        $this->assertSame('online', $log->properties['payment_method']);
        $this->assertTrue($log->properties['differs']);
    }

    public function test_a_negotiated_price_equal_to_the_catalogue_price_is_still_logged_but_marked_not_different(): void
    {
        $w = $this->world();
        $admin = $this->makeSuperAdmin();

        $booking = $this->actingAs($admin)->app->make(CreateBookingAction::class)->execute($this->payload($w, ['price_quoted' => '500.00']));

        $log = ActivityLog::where('subject_id', $booking->id)->where('description', 'negotiated price set')->firstOrFail();
        $this->assertFalse($log->properties['differs']);
    }

    public function test_a_normal_customer_booking_writes_no_negotiated_price_entry(): void
    {
        $w = $this->world();

        $booking = $this->actingAs($w['customer'], 'sanctum')->app->make(CreateBookingAction::class)->execute($this->payload($w));

        $this->assertEquals(500.0, (float) $booking->price_quoted);
        $this->assertSame(0, ActivityLog::where('description', 'negotiated price set')->count());
    }

    public function test_the_api_customer_path_cannot_produce_a_negotiated_price_entry(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => 'online', 'price_quoted' => 1,
        ])->assertStatus(201);

        $this->assertEquals(500.0, (float) Booking::firstOrFail()->price_quoted);
        $this->assertSame(0, ActivityLog::where('description', 'negotiated price set')->count());
    }
}
