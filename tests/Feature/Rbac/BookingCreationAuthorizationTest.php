<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Bookings\Index;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * bookings.create/createCustomer/addNewAddress had zero enforcement (Slice 2
 * finding #6/7/8). createCustomer runs before a zone is necessarily chosen
 * (customers carry no franchise column) so it's gated by canAnywhere() —
 * "does this user hold bookings.create in ANY assignment" — while
 * addNewAddress/createBooking are properly zone-scoped once a zone exists,
 * mirroring Bookings\Show::bookingScope().
 *
 * Every actor below also holds bookings.view -- Bookings\Index::mount() now
 * requires it to even open the screen (bookings.view was seeded but never
 * checked; same-session fix, see Commissions\Index's identical one).
 * Without it every actor here would 403 at mount() before ever reaching
 * createCustomer/addNewAddress/createBooking, testing the wrong boundary.
 * Every real seeded role that holds bookings.create also holds bookings.view
 * (they're always granted together) -- granting both here mirrors a real
 * actor, not a shortcut.
 */
class BookingCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function makeZone(): Zone
    {
        $franchise = $this->makeFranchise();

        return Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Booking Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
    }

    public function test_user_without_bookings_create_anywhere_cannot_create_customer(): void
    {
        // support role holds bookings.view only, per the seeder — never
        // bookings.create — this must be blocked outright.
        $actor = $this->makeUserWithNoPermissions();
        $this->grantPermission($actor, 'bookings.view');

        Livewire::actingAs($actor)->test(Index::class)
            ->set('newCustomerName', 'Blocked Customer')
            ->set('newCustomerPhone', '9999999999')
            ->call('createCustomer')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('users', ['phone' => '9999999999']);
    }

    public function test_user_holding_bookings_create_anywhere_can_create_customer(): void
    {
        // Scoped to an arbitrary franchise — createCustomer has no zone
        // context yet, so ANY assignment holding bookings.create suffices.
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('bookings.create', 'franchise', $franchise->id);
        $this->grantPermission($actor, 'bookings.view');

        Livewire::actingAs($actor)->test(Index::class)
            ->set('newCustomerName', 'Allowed Customer')
            ->set('newCustomerPhone', '9888888888')
            ->call('createCustomer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['phone' => '9888888888', 'role' => 'customer']);
    }

    public function test_zone_scoped_grant_can_create_booking_in_that_zone(): void
    {
        $zone = $this->makeZone();
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Cat', 'slug' => 'cat',
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Svc', 'slug' => 'svc',
            'base_price' => 200, 'price_type' => 'fixed', 'duration_estimate_mins' => 30,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
        $actor = $this->makeUserWithPermission('bookings.create', 'zone', $zone->id);
        $this->grantPermission($actor, 'bookings.view');

        $component = Livewire::actingAs($actor)->test(Index::class)
            ->set('newCustomerName', 'Zone Customer')
            ->set('newCustomerPhone', '9777777777')
            ->call('createCustomer')
            ->assertHasNoErrors()
            ->set('selectedZoneId', $zone->id)
            ->set('newAddressLine', 'Test Address')
            ->set('newAddressLat', 1.0)
            ->set('newAddressLng', 1.0)
            ->call('addNewAddress')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('addresses', ['address_line' => 'Test Address', 'zone_id' => $zone->id]);

        // OrderCodeService was MySQL-only (raw upsert) as of when this test
        // was first written, which forced this test to only verify the
        // permission gate and expect the downstream query to fail on
        // sqlite. Since fixed (driver-aware — sqlite gets an equivalent
        // lockForUpdate() path with the same atomicity guarantee, MySQL
        // unchanged), the full flow now genuinely completes end-to-end.
        $component->set('selectedServiceId', $service->id)
            ->set('priceQuoted', '200')
            ->call('createBooking')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bookings', ['zone_id' => $zone->id, 'price_quoted' => 200]);
    }

    public function test_zone_scoped_grant_does_not_cover_a_different_zone(): void
    {
        $ownZone = $this->makeZone();
        $otherZone = $this->makeZone();
        $actor = $this->makeUserWithPermission('bookings.create', 'zone', $ownZone->id);
        $this->grantPermission($actor, 'bookings.view');

        Livewire::actingAs($actor)->test(Index::class)
            ->set('newCustomerName', 'Cross Zone Customer')
            ->set('newCustomerPhone', '9666666666')
            ->call('createCustomer')
            ->assertHasNoErrors()
            ->set('selectedZoneId', $otherZone->id)
            ->set('newAddressLine', 'Cross Zone Address')
            ->set('newAddressLat', 1.0)
            ->set('newAddressLng', 1.0)
            ->call('addNewAddress')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('addresses', ['address_line' => 'Cross Zone Address']);
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Index::class)
            ->set('newCustomerName', 'Super Admin Customer')
            ->set('newCustomerPhone', '9555555555')
            ->call('createCustomer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['phone' => '9555555555']);
    }
}
