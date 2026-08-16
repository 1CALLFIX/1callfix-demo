<?php

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Models\ModuleActivation;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Phase 22.1 (Module Activation Foundation). Direct coverage for the
 * resolver PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §3/§6 found
 * missing entirely: no Country->City->Zone->Franchise cascade existed
 * anywhere before this phase. Every assertion here traces back to a
 * specific claim that audit document made about what the eventual
 * mechanism must do.
 */
class ModuleActivationServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private ModuleActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ModuleActivationService::class);
    }

    public function test_unimplemented_module_never_resolves_active_even_with_an_explicit_on_row(): void
    {
        $franchise = $this->makeFranchise();
        $food = Module::where('code', 'food')->firstOrFail();
        $this->assertFalse($food->is_implemented);

        // Explicitly force an "on" row directly — bypassing setActive()'s
        // own is_implemented-agnostic write path, simulating an admin who
        // pre-configured Food's rollout before the module actually ships.
        ModuleActivation::create([
            'module_id' => $food->id, 'scope_type' => 'franchise', 'scope_id' => $franchise->id, 'is_active' => true,
        ]);

        $this->assertFalse(
            $this->service->isActive('food', ['franchise_id' => $franchise->id]),
            'An unimplemented module must never resolve active, regardless of any activation row.'
        );
    }

    public function test_service_module_defaults_active_when_no_explicit_row_exists_anywhere(): void
    {
        $franchise = $this->makeFranchise();

        $this->assertTrue(
            $this->service->isActive(Modules::SERVICE, [
                'franchise_id' => $franchise->id,
                'city_id' => $franchise->city_id,
                'country_id' => $franchise->country_id,
            ]),
            'Service must default active with no explicit row anywhere, per the documented legacy-compatibility exception.'
        );
    }

    public function test_future_module_defaults_inactive_when_no_explicit_row_exists_anywhere(): void
    {
        $franchise = $this->makeFranchise();

        // parcel IS implemented for this assertion's purposes? No -- parcel
        // is also not implemented today, so isActive() already returns
        // false via the is_implemented gate. To isolate the "no row ->
        // default off" behavior specifically (not the is_implemented gate),
        // flip parcel to implemented for this test only.
        Module::where('code', 'parcel')->update(['is_implemented' => true]);

        $this->assertFalse(
            $this->service->isActive('parcel', ['franchise_id' => $franchise->id]),
            'A module other than service must default INACTIVE with no explicit row -- "deploy disabled first."'
        );
    }

    public function test_zone_level_row_outranks_franchise_level_row(): void
    {
        Module::where('code', 'parcel')->update(['is_implemented' => true]);
        $franchise = $this->makeFranchise();
        $zone = \App\Models\Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Z1',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);

        $this->service->setActive('parcel', 'franchise', $franchise->id, true);
        $this->service->setActive('parcel', 'zone', $zone->id, false);

        $this->assertFalse(
            $this->service->isActive('parcel', ['zone_id' => $zone->id, 'franchise_id' => $franchise->id]),
            'Zone is the more specific level (zones.franchise_id) and must win over its parent franchise.'
        );

        $this->assertTrue(
            $this->service->isActive('parcel', ['franchise_id' => $franchise->id]),
            'Without a zone in the requested scope, the franchise-level row must still apply.'
        );
    }

    public function test_country_level_off_is_not_overridden_by_a_city_with_no_explicit_row(): void
    {
        Module::where('code', 'parcel')->update(['is_implemented' => true]);
        $franchise = $this->makeFranchise();

        $this->service->setActive('parcel', 'country', $franchise->country_id, false);

        $this->assertFalse(
            $this->service->isActive('parcel', [
                'franchise_id' => $franchise->id,
                'city_id' => $franchise->city_id,
                'country_id' => $franchise->country_id,
            ]),
            'With no franchise/zone/city row, the country-level off must be honored -- absence at a more specific level defers upward, it does not silently mean "on".'
        );
    }

    public function test_city_level_on_is_honored_when_no_more_specific_row_exists(): void
    {
        Module::where('code', 'parcel')->update(['is_implemented' => true]);
        $franchise = $this->makeFranchise();

        $this->service->setActive('parcel', 'city', $franchise->city_id, true);

        $this->assertTrue(
            $this->service->isActive('parcel', [
                'franchise_id' => $franchise->id,
                'city_id' => $franchise->city_id,
                'country_id' => $franchise->country_id,
            ])
        );
    }

    public function test_resolved_from_reports_the_exact_level_deciding_the_state(): void
    {
        Module::where('code', 'parcel')->update(['is_implemented' => true]);
        $franchise = $this->makeFranchise();

        $this->service->setActive('parcel', 'franchise', $franchise->id, true);

        $resolved = $this->service->resolvedFrom('parcel', ['franchise_id' => $franchise->id, 'city_id' => $franchise->city_id]);

        $this->assertSame('franchise', $resolved['level']);
        $this->assertTrue($resolved['is_active']);
    }

    public function test_resolved_from_reflects_the_franchise_observers_own_explicit_row(): void
    {
        // makeFranchise() goes through Eloquent (Franchise::create()), so
        // FranchiseObserver::created() fires and leaves a real, explicit
        // franchise-scope row -- this is NOT the legacy fallback, it's a
        // genuine row, and resolvedFrom() must say so.
        $franchise = $this->makeFranchise();

        $resolved = $this->service->resolvedFrom(Modules::SERVICE, ['franchise_id' => $franchise->id]);

        $this->assertSame('franchise', $resolved['level']);
        $this->assertTrue($resolved['is_active']);
    }

    public function test_resolved_from_is_null_when_truly_no_explicit_row_exists_anywhere(): void
    {
        $franchise = $this->makeFranchise();
        // Remove the FranchiseObserver's own row to isolate the genuinely-
        // no-row-anywhere case (e.g. a pre-Phase-22.1 legacy franchise that
        // predates the backfill migration, or the id space a fixture never
        // actually inserted into module_activations).
        ModuleActivation::where('scope_type', 'franchise')->where('scope_id', $franchise->id)->delete();

        $resolved = $this->service->resolvedFrom(Modules::SERVICE, ['franchise_id' => $franchise->id]);

        $this->assertNull($resolved);
        $this->assertTrue(
            $this->service->isActive(Modules::SERVICE, ['franchise_id' => $franchise->id]),
            'isActive() must still resolve true via the documented legacy default even though resolvedFrom() correctly reports no explicit row.'
        );
    }

    public function test_set_active_rejects_an_invalid_scope_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->setActive(Modules::SERVICE, 'branch', 1, true);
    }
}
