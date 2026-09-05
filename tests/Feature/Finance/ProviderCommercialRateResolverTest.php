<?php

namespace Tests\Feature\Finance;

use App\Models\Franchise;
use App\Models\ProviderCommissionAgreement;
use App\Models\Setting;
use App\Services\ProviderCommercialRateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider Commercial Rate Resolver phase — Steps 10 A (resolver unit
 * coverage) and part of E (the exact production-parity assertion). Also
 * covers the backfill migration's actual behavior directly (not a
 * reimplementation of its query) since RefreshDatabase already runs every
 * migration before each test — there's no pre-existing "production" row to
 * observe it act on, so these tests re-run the real migration file's up()
 * against a manually-inserted 0% row to prove it does what it claims.
 */
class ProviderCommercialRateResolverTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function resolver(): ProviderCommercialRateResolver
    {
        return app(ProviderCommercialRateResolver::class);
    }

    public function test_franchise_column_wins_over_global_default_when_set(): void
    {
        [, , $franchise] = $this->makeFranchiseTree(); // platform_fee_percent = 5

        $this->assertEquals(5.0, $this->resolver()->resolve($franchise, null));
        $this->assertSame('franchise', $this->resolver()->resolvedTier($franchise, null));
    }

    public function test_null_franchise_column_falls_through_to_seeded_global_default(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $franchise->update(['platform_fee_percent' => null]);

        // 2026_09_05_004000 seeds this to 30 — already ran via RefreshDatabase.
        $this->assertEquals(30.0, $this->resolver()->resolve($franchise, null));
        $this->assertSame('global', $this->resolver()->resolvedTier($franchise, null));
    }

    public function test_production_parity_scenario_resolves_to_thirty_percent(): void
    {
        // The exact post-deploy production shape: no agreement, franchise
        // column NULL (post-backfill) — must resolve to the real global
        // default, never silently to 0.
        [, , $franchise] = $this->makeFranchiseTree();
        $franchise->update(['platform_fee_percent' => null]);

        $this->assertEquals(30.0, $this->resolver()->resolve($franchise, null));
    }

    public function test_provider_agreement_wins_over_franchise_and_global(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree(); // platform_fee_percent = 5
        $provider = $this->makeProviderIn($franchise, $zone);
        ProviderCommissionAgreement::create(['provider_id' => $provider->id, 'platform_fee_percent' => 22.5]);

        $this->assertEquals(22.5, $this->resolver()->resolve($franchise, $provider));
        $this->assertSame('agreement', $this->resolver()->resolvedTier($franchise, $provider));
    }

    public function test_provider_agreement_wins_even_when_franchise_column_is_null(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['platform_fee_percent' => null]);
        $provider = $this->makeProviderIn($franchise, $zone);
        ProviderCommissionAgreement::create(['provider_id' => $provider->id, 'platform_fee_percent' => 15]);

        $this->assertEquals(15.0, $this->resolver()->resolve($franchise, $provider));
    }

    public function test_a_provider_with_no_agreement_falls_through_to_franchise_column(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree(); // platform_fee_percent = 5
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->assertEquals(5.0, $this->resolver()->resolve($franchise, $provider));
        $this->assertSame('franchise', $this->resolver()->resolvedTier($franchise, $provider));
    }

    /**
     * The global tier deliberately does NOT pass a franchise-scope hint to
     * Setting::get() — proven here by setting a franchise-scoped Setting
     * override that the resolver must NOT pick up (it should still see the
     * plain global value), confirming tier 2 and tier 3 stay independent as
     * documented in ProviderCommercialRateResolver's own docblock.
     */
    public function test_global_tier_ignores_a_franchise_scoped_setting_override(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $franchise->update(['platform_fee_percent' => null]);
        Setting::set('commission.default_platform_fee_percent', '99', 'franchise', $franchise->id);

        $this->assertEquals(30.0, $this->resolver()->resolve($franchise, null));
    }

    // ---------------------- Backfill migration behavior ----------------------

    private function loadBackfillMigration()
    {
        return require base_path('database/migrations/2026_09_05_002000_backfill_unconfigured_franchise_platform_fee.php');
    }

    public function test_backfill_migration_nulls_out_a_franchise_stuck_at_the_zero_default(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        // Bypass Eloquent so updated_at isn't bumped — simulates a franchise
        // untouched since creation, exactly the production scenario.
        DB::table('franchises')->where('id', $franchise->id)->update(['platform_fee_percent' => 0]);

        $this->loadBackfillMigration()->up();

        $this->assertNull($franchise->fresh()->platform_fee_percent);
    }

    public function test_backfill_migration_leaves_a_genuinely_configured_franchise_alone(): void
    {
        [, , $franchise] = $this->makeFranchiseTree(); // platform_fee_percent = 5, never touched

        $this->loadBackfillMigration()->up();

        $this->assertEquals(5.0, $franchise->fresh()->platform_fee_percent);
    }

    public function test_backfill_migration_still_nulls_a_zero_franchise_edited_since_creation_but_logs_a_warning(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        // Push created_at clearly into the past first (a same-second
        // created_at/updated_at would otherwise round-trip as equal at
        // this column's timestamp precision) — then an unrelated edit
        // bumps updated_at to "now" (name/status, never the commission
        // fields), and platform_fee_percent is forced to 0 via a raw
        // update (bypassing Eloquent) so updated_at isn't touched again.
        // Net state: platform_fee_percent = 0, updated_at != created_at,
        // exactly the ambiguous case the migration's docblock describes.
        DB::table('franchises')->where('id', $franchise->id)->update(['created_at' => now()->subDay()]);
        $franchise->update(['state' => 'Some State']);
        DB::table('franchises')->where('id', $franchise->id)->update(['platform_fee_percent' => 0]);

        Log::shouldReceive('warning')->once()->with(
            \Mockery::pattern('/Backfilling platform_fee_percent to NULL on a franchise edited since creation/'),
            \Mockery::any()
        );

        $this->loadBackfillMigration()->up();

        $this->assertNull($franchise->fresh()->platform_fee_percent);
    }
}
