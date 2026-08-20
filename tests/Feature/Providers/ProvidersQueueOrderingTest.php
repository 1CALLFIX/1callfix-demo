<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Index;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Polish + AI session, Part 1 item 3 — "should feel like a queue to
 * clear, not a spreadsheet." Regression coverage for the new oldest-first
 * ordering (pending only) and the new search box, on top of the existing
 * scopeQuery/pagination behavior this session must not regress.
 */
class ProvidersQueueOrderingTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeProviderWithName(string $name, $franchise, $zone, string $kycStatus = 'pending'): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => $kycStatus, 'is_active' => true,
        ]);
    }

    public function test_pending_queue_shows_oldest_application_first(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $admin = $this->makeSuperAdmin();

        $newer = $this->makeProviderWithName('Newer Applicant', $franchise, $zone);
        $newer->forceFill(['created_at' => now()->subDay()])->save();
        $older = $this->makeProviderWithName('Older Applicant', $franchise, $zone);
        $older->forceFill(['created_at' => now()->subDays(5)])->save();

        $component = Livewire::actingAs($admin)->test(Index::class)->set('statusFilter', 'pending');

        $html = $component->html();
        $this->assertTrue(
            strpos($html, 'Older Applicant') < strpos($html, 'Newer Applicant'),
            'Expected the older pending application to render before the newer one.'
        );
    }

    public function test_approved_tab_stays_newest_first(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $admin = $this->makeSuperAdmin();

        $older = $this->makeProviderWithName('Older Approved', $franchise, $zone, 'approved');
        $older->forceFill(['created_at' => now()->subDays(5)])->save();
        $newer = $this->makeProviderWithName('Newer Approved', $franchise, $zone, 'approved');
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $html = Livewire::actingAs($admin)->test(Index::class)->set('statusFilter', 'approved')->html();

        $this->assertTrue(
            strpos($html, 'Newer Approved') < strpos($html, 'Older Approved'),
            'Expected Approved to stay newest-first (unchanged from before this session).'
        );
    }

    public function test_search_filters_by_name(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $admin = $this->makeSuperAdmin();

        $this->makeProviderWithName('Alice Johnson', $franchise, $zone);
        $this->makeProviderWithName('Bob Smith', $franchise, $zone);

        Livewire::actingAs($admin)->test(Index::class)
            ->set('search', 'Alice')
            ->assertSee('Alice Johnson')
            ->assertDontSee('Bob Smith');
    }

    public function test_row_level_scoping_still_applies_with_the_new_ordering(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB, $zoneB] = $this->makeFranchiseTree();

        $insideProvider = $this->makeProviderWithName('Inside Provider', $franchiseA, $zoneA);
        $outsideProvider = $this->makeProviderWithName('Outside Provider', $franchiseB, $zoneB);

        $actor = $this->makeUserWithPermission('providers.view', 'franchise', $franchiseA->id);

        Livewire::actingAs($actor)->test(Index::class)
            ->set('statusFilter', 'pending')
            ->assertSee('Inside Provider')
            ->assertDontSee('Outside Provider');
    }
}
