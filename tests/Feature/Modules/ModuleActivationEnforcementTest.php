<?php

namespace Tests\Feature\Modules;

use App\Actions\CreateBookingAction;
use App\Exceptions\ModuleNotActiveException;
use App\Services\ModuleActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.1 (Module Activation Foundation). PHASE_22_PLATFORM_CAPABILITY_
 * RECOVERY_AUDIT.md §16 named a real, present "silent-toggle risk": a
 * stored activation flag with zero code ever reading it. This is the test
 * proving that gap is actually closed at the one real choke point every
 * booking passes through (CreateBookingAction), not just that the
 * resolver's own logic is correct in isolation (ModuleActivationServiceTest
 * covers that).
 */
class ModuleActivationEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_booking_creation_still_succeeds_by_default_with_no_admin_action_taken(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'online',
        ]);

        $this->assertNotNull($booking->id, 'Existing Service booking flow must be completely unaffected for a franchise created the normal way.');
    }

    public function test_booking_creation_is_blocked_when_an_admin_deactivates_service_for_the_franchise(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        app(ModuleActivationService::class)->setActive('service', 'franchise', $franchise->id, false);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'online',
        ]);
    }

    public function test_booking_creation_is_blocked_when_deactivated_at_the_country_level_with_no_franchise_override(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        // Remove the franchise-level row FranchiseObserver created, so
        // there is genuinely nothing between this booking and the
        // country-level deactivation -- proving the country level actually
        // reaches all the way down when nothing more specific overrides it.
        \App\Models\ModuleActivation::where('scope_type', 'franchise')->where('scope_id', $franchise->id)->delete();
        app(ModuleActivationService::class)->setActive('service', 'country', $country->id, false);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'online',
        ]);
    }

    public function test_a_franchise_level_reactivation_overrides_a_country_level_deactivation(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        app(ModuleActivationService::class)->setActive('service', 'country', $country->id, false);
        app(ModuleActivationService::class)->setActive('service', 'franchise', $franchise->id, true);

        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'online',
        ]);

        $this->assertNotNull($booking->id, 'The more specific franchise-level ON must win over the country-level OFF.');
    }
}
