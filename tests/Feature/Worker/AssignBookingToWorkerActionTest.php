<?php

namespace Tests\Feature\Worker;

use App\Actions\AssignBookingToWorkerAction;
use App\Models\Address;
use App\Models\Booking;
use App\Models\City;
use App\Models\Country;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\Franchise;
use App\Models\PartnerWorker;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Priority 2 required test areas E (Worker delegation) + F (Worker
 * authorization). AssignBookingToWorkerAction (Phase B0.2) already
 * implements every boundary the mission brief lists — this locks each one
 * in with a real test rather than leaving it proven only by memory of a
 * manual production test-and-revert cycle.
 */
class AssignBookingToWorkerActionTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $franchise;
    private Zone $zone;
    private ServiceCategory $category;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create(['name' => 'Testland', 'code' => 'T'.Str::random(1), 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        $city = City::create(['country_id' => $country->id, 'name' => 'City '.Str::random(6), 'is_active' => true]);
        $this->franchise = Franchise::create([
            'name' => 'Franchise', 'slug' => Str::slug('franchise-'.Str::random(8)),
            'city' => $city->name, 'country_id' => $country->id, 'city_id' => $city->id, 'status' => 'active',
        ]);
        $this->zone = Zone::create([
            'franchise_id' => $this->franchise->id, 'name' => 'Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
        $this->category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Cat', 'slug' => 'cat-'.Str::random(6),
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->service = Service::create([
            'category_id' => $this->category->id, 'name' => 'Svc', 'slug' => 'svc-'.Str::random(6),
            'base_price' => 500, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
    }

    private function makeProvider(): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $this->franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    private function makeWorker(bool $active = true): FieldWorker
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Worker', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $this->franchise->id,
        ]);

        return FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'kyc_status' => 'approved', 'is_active' => $active,
        ]);
    }

    private function linkToTeam(Provider $partner, FieldWorker $worker, string $status = 'active'): void
    {
        PartnerWorker::create(['provider_id' => $partner->id, 'field_worker_id' => $worker->id, 'status' => $status]);
    }

    private function giveServiceCapability(FieldWorker $worker, ?int $categoryId = null): void
    {
        FieldWorkerCapability::create([
            'field_worker_id' => $worker->id, 'capability_type' => 'service_technician',
            'service_category_id' => $categoryId,
        ]);
    }

    private function makeBooking(Provider $provider, string $status = 'assigned'): Booking
    {
        $customer = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Customer', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
        ]);
        $address = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'label' => 'Home', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Addr',
        ]);

        return Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'customer_id' => $customer->id, 'provider_id' => $provider->id,
            'service_id' => $this->service->id, 'address_id' => $address->id,
            'status' => $status, 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);
    }

    public function test_partner_can_assign_an_eligible_active_team_member(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner);

        $result = app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);

        $this->assertSame($worker->id, $result->assigned_worker_id);
    }

    public function test_capability_scoped_to_a_different_category_still_matches_via_null_scope(): void
    {
        // A capability with service_category_id = null applies to ANY
        // Service-module category — confirms the "or whereNull" branch.
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, null);
        $booking = $this->makeBooking($partner);

        $result = app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);

        $this->assertSame($worker->id, $result->assigned_worker_id);
    }

    public function test_partner_cannot_assign_a_booking_they_do_not_own(): void
    {
        $partner = $this->makeProvider();
        $otherPartner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($otherPartner); // accepted by someone else

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not accepted by you');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_completed_booking_cannot_be_assigned(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner, 'completed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be assigned to a worker');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_cancelled_booking_cannot_be_assigned(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner, 'cancelled');

        $this->expectException(\RuntimeException::class);
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_inactive_worker_cannot_receive_assignment(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker(active: false);
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_partner_cannot_assign_a_worker_not_on_their_team(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker(); // never linked to this partner
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an active member');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_partner_cannot_assign_an_unrelated_partners_worker(): void
    {
        $partner = $this->makeProvider();
        $otherPartner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($otherPartner, $worker); // on someone else's team only
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an active member');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_suspended_team_link_cannot_receive_assignment(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker, 'suspended');
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an active member');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_worker_without_matching_capability_is_rejected(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        // No capability granted at all.
        $booking = $this->makeBooking($partner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not have the required service capability');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_worker_with_capability_scoped_to_a_different_category_is_rejected(): void
    {
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $otherCategory = ServiceCategory::create([
            'module' => 'service', 'name' => 'Other Cat', 'slug' => 'other-cat-'.Str::random(6),
            'image' => 'categories/x.png', 'sort_order' => 2, 'is_active' => true,
        ]);
        $this->giveServiceCapability($worker, $otherCategory->id); // wrong category
        $booking = $this->makeBooking($partner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not have the required service capability');
        app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);
    }

    public function test_assignment_does_not_change_provider_id_or_booking_status(): void
    {
        // provider_id stays the accountable Partner for commission purposes
        // — assigning a worker is a side annotation, not a reassignment.
        $partner = $this->makeProvider();
        $worker = $this->makeWorker();
        $this->linkToTeam($partner, $worker);
        $this->giveServiceCapability($worker, $this->category->id);
        $booking = $this->makeBooking($partner, 'provider_en_route');

        $result = app(AssignBookingToWorkerAction::class)->execute($booking->id, $partner, $worker->id);

        $this->assertSame($partner->id, $result->provider_id);
        $this->assertSame('provider_en_route', $result->status);
    }
}
