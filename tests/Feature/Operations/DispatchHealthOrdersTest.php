<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\Health;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Models\TaxiRide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Command Center mission (Operations phase) — DispatchHealthService
 * was found completely blind to Parcel/Taxi/Marketplace dispatch: it only
 * ever queried DispatchAttempt::booking_id, never the polymorphic
 * dispatchable_type/dispatchable_id pair those three verticals' own
 * DispatchService methods write to (Phase 22.4 onward). This suite covers
 * the extension — same stale-offer/exhausted-order signals, same
 * operations.view scopeQuery() gate, now for all three order types — kept
 * separate from OperationsHealthTest (Booking-only) since it exercises a
 * different set of fixtures/models entirely.
 */
class DispatchHealthOrdersTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use TaxiRideFixtureHelpers;
    use MarketplaceFixtureHelpers;

    private function makeStaleParcelOffer(): array
    {
        Setting::set('dispatch.offer_response_timeout_minutes', '2');
        $scenario = $this->makeParcelOrderScenario('searching_worker');

        DispatchAttempt::create([
            'dispatchable_type' => ParcelOrder::class,
            'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => FieldWorker::class,
            'notifiable_id' => $scenario['rider']->id,
            'status' => 'notified',
            'notified_at' => now()->subMinutes(10),
        ]);

        return $scenario;
    }

    public function test_stale_parcel_offer_visible_to_scoped_operator(): void
    {
        $scenario = $this->makeStaleParcelOffer();
        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee($scenario['order']->code);
    }

    public function test_stale_parcel_offer_hidden_from_a_different_franchises_operator(): void
    {
        $scenario = $this->makeStaleParcelOffer();
        $other = $this->makeParcelOrderScenario('searching_worker'); // different franchise
        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $other['franchise']->id);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertDontSee($scenario['order']->code);
    }

    public function test_exhausted_parcel_order_counted_when_only_terminal_attempts_remain(): void
    {
        $scenario = $this->makeParcelOrderScenario('searching_worker');

        DispatchAttempt::create([
            'dispatchable_type' => ParcelOrder::class,
            'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => FieldWorker::class,
            'notifiable_id' => $scenario['rider']->id,
            'status' => 'timeout',
            'notified_at' => now()->subMinutes(10),
            'responded_at' => now()->subMinutes(9),
        ]);

        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee($scenario['order']->code);
    }

    public function test_exhausted_taxi_ride_surfaces_alongside_parcel(): void
    {
        $scenario = $this->makeTaxiRideScenario('searching_driver');

        DispatchAttempt::create([
            'dispatchable_type' => TaxiRide::class,
            'dispatchable_id' => $scenario['ride']->id,
            'notifiable_type' => FieldWorker::class,
            'notifiable_id' => $scenario['driver']->id,
            'status' => 'rejected',
            'notified_at' => now()->subMinutes(10),
            'responded_at' => now()->subMinutes(9),
        ]);

        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee($scenario['ride']->code);
    }

    public function test_exhausted_marketplace_delivery_order_surfaces(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery']);
        $rider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);

        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class,
            'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => FieldWorker::class,
            'notifiable_id' => $rider->id,
            'status' => 'timeout',
            'notified_at' => now()->subMinutes(10),
            'responded_at' => now()->subMinutes(9),
        ]);

        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(Health::class)
            ->assertOk()
            ->assertSee($scenario['order']->code);
    }

    public function test_marketplace_pickup_order_never_counted_as_exhausted(): void
    {
        // order_type=pickup never dispatches at all (MarketplaceDispatchJob's
        // own docblock) -- ready+pickup with no dispatch attempts must not
        // be reported as "exhausted", it's simply not a delivery order.
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'pickup']);
        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $scenario['franchise']->id);

        $dispatchHealth = Livewire::actingAs($actor)->test(Health::class)
            ->viewData('dispatchHealth');

        $this->assertFalse(collect($dispatchHealth['exhausted_orders'])->contains('id', $scenario['order']->id));
    }

    public function test_super_admin_sees_orders_across_franchises(): void
    {
        $scenario = $this->makeStaleParcelOffer();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Health::class)
            ->assertOk()
            ->assertSee($scenario['order']->code);
    }
}
