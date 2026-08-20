<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Franchises\Manage;
use App\Models\Franchise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a real, live gap found while writing
 * RBAC_SCOPE_MATRIX.md this session: Franchises\Manage::save() has always
 * checked franchises.manage, but update()/toggleStatus()/deleteFranchise()
 * never did — any authenticated admin-panel actor could edit commission
 * rates, toggle a franchise active/inactive, or delete one, with zero
 * permission check. Fixed in the same commit as this test.
 */
class FranchisesAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    /**
     * Since Phase 11 added a mount()-level franchises.manage gate to this
     * whole screen (it never had a separate .view permission), an actor
     * with zero permissions is now denied at mount() — never reaches
     * update() to trigger its own action-level check.
     */
    public function test_update_denied_without_permission(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertDatabaseMissing('franchises', ['id' => $franchise->id, 'commission_value' => 99]);
    }

    public function test_update_allowed_with_correct_country_scope(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('franchises.manage', 'country', $franchise->country_id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('editFranchiseId', $franchise->id)
            ->set('editName', 'Renamed')
            ->set('editCountryId', $franchise->country_id)
            ->set('editCityId', $franchise->city_id)
            ->set('editCommissionModel', 'revenue_share')
            ->set('editCommissionValue', '15')
            ->set('editPlatformFeePercent', '5')
            ->set('editStatus', 'active')
            ->call('update')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('franchises', ['id' => $franchise->id, 'commission_value' => 15]);
    }

    public function test_toggle_status_denied_without_permission(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertSame('active', $franchise->fresh()->status);
    }

    public function test_delete_denied_without_permission(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertDatabaseHas('franchises', ['id' => $franchise->id]);
    }

    public function test_wrong_country_scope_is_denied(): void
    {
        $ownFranchise = $this->makeFranchise();
        $otherFranchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('franchises.manage', 'country', $ownFranchise->country_id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->call('toggleStatus', $otherFranchise->id)
            ->assertHasErrors(['permission']);
    }

    /**
     * Platform-structure policy pass (follow-up to item 51, 2026-08-20):
     * franchises.manage's own allowed grant scope is global/country/city
     * (see AuthorizationService::RESTRICTED_GRANT_SCOPES's own docblock) --
     * a franchise-scoped grant was already unreachable via the write-side
     * country_id-only check before this pass (no scope hint key for
     * franchise_id was ever passed), but this proves the NEW
     * canWithRestrictedScope() defense-in-depth layer also independently
     * rejects it, not just the pre-existing scope-hint shape.
     */
    public function test_franchise_scoped_grant_is_still_denied_after_the_restricted_scope_change(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('franchises.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->call('toggleStatus', $franchise->id)
            ->assertHasErrors(['permission']);

        $this->assertSame('active', $franchise->fresh()->status);
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $franchise = $this->makeFranchise();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('toggleStatus', $franchise->id)
            ->assertHasNoErrors();

        $this->assertSame('inactive', $franchise->fresh()->status);
    }

    /**
     * Admin Command Center completion session, Geography + Maps phase
     * (2026-08-20) — every mutation above already correctly checks
     * franchises.manage against ['country_id' => ...], but render()'s list
     * never applied any row-level scope at all — a country-scoped grant
     * could browse every OTHER country's franchises too.
     */
    public function test_country_scoped_grant_sees_only_its_own_countrys_franchises_in_the_list(): void
    {
        $mine = $this->makeFranchise();
        $other = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('franchises.manage', 'country', $mine->country_id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->assertSee($mine->name)
            ->assertDontSee($other->name);
    }
}
