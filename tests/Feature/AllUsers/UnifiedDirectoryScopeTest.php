<?php

namespace Tests\Feature\AllUsers;

use App\Livewire\AllUsers\Index as AllUsersIndex;
use App\Models\FieldWorker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Unified "All Users" Directory session, Part 1 — "the single most
 * important requirement in this prompt." One test per real person-type
 * this screen aggregates (customer, provider, worker, staff), the same
 * discipline as the Export Everywhere session's per-entity scope suite —
 * a franchise-scoped admin must never see another franchise's people
 * here, regardless of which of the four different geography paths that
 * person's row actually resolves through.
 */
class UnifiedDirectoryScopeTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function viewRows($actor)
    {
        return Livewire::actingAs($actor)->test(AllUsersIndex::class)->viewData('users');
    }

    public function test_customers_are_scoped_via_their_saved_address_franchise(): void
    {
        $own = $this->makeBookingScenario(); // includes a customer + address in one franchise
        $other = $this->makeBookingScenario(); // a different franchise entirely

        $viewer = $this->makeUserWithPermission('users.directory.view', 'franchise', $own['franchise']->id);

        $rows = $this->viewRows($viewer);

        $this->assertTrue($rows->contains('id', $own['customer']->id));
        $this->assertFalse($rows->contains('id', $other['customer']->id));
    }

    public function test_providers_are_scoped_via_their_provider_profile_franchise(): void
    {
        [, , $ownFranchise, $ownZone] = $this->makeFranchiseTree();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $ownProvider = $this->makeProviderIn($ownFranchise, $ownZone);
        $otherProvider = $this->makeProviderIn($otherFranchise, $otherZone);

        $viewer = $this->makeUserWithPermission('users.directory.view', 'franchise', $ownFranchise->id);

        $rows = $this->viewRows($viewer);

        $this->assertTrue($rows->contains('id', $ownProvider->user_id));
        $this->assertFalse($rows->contains('id', $otherProvider->user_id));
    }

    public function test_workers_are_scoped_via_their_field_worker_profile_franchise(): void
    {
        [, , $ownFranchise, $ownZone] = $this->makeFranchiseTree();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $ownWorker = $this->makeFieldWorkerIn($ownFranchise, $ownZone);
        $otherWorker = $this->makeFieldWorkerIn($otherFranchise, $otherZone);

        $viewer = $this->makeUserWithPermission('users.directory.view', 'franchise', $ownFranchise->id);

        $rows = $this->viewRows($viewer);

        $this->assertTrue($rows->contains('id', $ownWorker->user_id));
        $this->assertFalse($rows->contains('id', $otherWorker->user_id));
    }

    public function test_staff_are_scoped_via_the_direct_users_franchise_column(): void
    {
        [, , $ownFranchise] = $this->makeFranchiseTree();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $ownStaff = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Own Operator', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'operator', 'status' => 'active', 'franchise_id' => $ownFranchise->id]);
        $otherStaff = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Other Operator', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'operator', 'status' => 'active', 'franchise_id' => $otherFranchise->id]);

        $viewer = $this->makeUserWithPermission('users.directory.view', 'franchise', $ownFranchise->id);

        $rows = $this->viewRows($viewer);

        $this->assertTrue($rows->contains('id', $ownStaff->id));
        $this->assertFalse($rows->contains('id', $otherStaff->id));
    }

    public function test_global_grant_sees_every_type_across_every_franchise(): void
    {
        $bookingScenario = $this->makeBookingScenario();
        [, , $franchiseB, $zoneB] = $this->makeFranchiseTree();
        $providerB = $this->makeProviderIn($franchiseB, $zoneB);

        $viewer = $this->makeUserWithPermission('users.directory.view', 'global');

        $rows = $this->viewRows($viewer);

        $this->assertTrue($rows->contains('id', $bookingScenario['customer']->id));
        $this->assertTrue($rows->contains('id', $providerB->user_id));
    }

    public function test_type_filter_provider_excludes_workers_and_vice_versa(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $worker = $this->makeFieldWorkerIn($franchise, $zone);
        $viewer = $this->makeUserWithPermission('users.directory.view', 'global');

        $providerRows = Livewire::actingAs($viewer)->test(AllUsersIndex::class)->set('typeFilter', 'provider')->viewData('users');
        $workerRows = Livewire::actingAs($viewer)->test(AllUsersIndex::class)->set('typeFilter', 'worker')->viewData('users');

        $this->assertTrue($providerRows->contains('id', $provider->user_id));
        $this->assertFalse($providerRows->contains('id', $worker->user_id));
        $this->assertTrue($workerRows->contains('id', $worker->user_id));
        $this->assertFalse($workerRows->contains('id', $provider->user_id));
    }

    public function test_a_user_holding_both_provider_and_field_worker_profiles_is_labelled_as_both(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        FieldWorker::create(['user_id' => $provider->user_id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'kyc_status' => 'pending', 'is_active' => true]);

        $component = Livewire::actingAs($this->makeSuperAdmin())->test(AllUsersIndex::class);
        $user = User::find($provider->user_id);

        $this->assertSame('Provider + Worker', $component->instance()->typeLabel($user));
    }

    // ============================== Export scope (same trait, same discipline) ==============================

    public function test_export_never_includes_another_franchises_row(): void
    {
        $own = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();
        $viewer = $this->makeUserWithPermission('users.directory.view', 'franchise', $own['franchise']->id);

        $csv = base64_decode(data_get(
            Livewire::actingAs($viewer)->test(AllUsersIndex::class)->call('exportUsersCsv')->effects,
            'download.content'
        ));

        $this->assertStringContainsString($own['customer']->phone, $csv);
        $this->assertStringNotContainsString($other['customer']->phone, $csv);
    }

    public function test_no_permission_cannot_view_the_screen_at_all(): void
    {
        $noPerm = $this->makeUserWithNoPermissions();

        Livewire::actingAs($noPerm)->test(AllUsersIndex::class)->assertForbidden();
    }
}
