<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Show as ProvidersShow;
use App\Models\Provider;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Prompt 25 follow-up -- Providers\Show::updateSkills() (added in
 * "feat(admin): complete service options and provider assignment") shipped
 * with zero test coverage. Closes that gap: valid category ids persist to
 * providers.skills (the array DispatchService::hasSkill() checks for real
 * dispatch eligibility), and the canReview() boundary it shares with
 * approve()/reject()/updatePriority() -- providers.review_kyc, scope-checked
 * against the provider's own zone_id/franchise_id -- is enforced the same
 * way here.
 *
 * updateSkills() filters submitted ids via
 * `ServiceCategory::whereIn('id', $ids)->where('is_active', true)->pluck('id')`
 * -- matching render()'s own dropdown query exactly, so a category
 * deactivated after being assigned drops out of providers.skills on the
 * next save rather than lingering. (Originally this only checked the row
 * still EXISTS, not is_active -- fixed as part of closing this test gap;
 * see test_inactive_category_ids_are_dropped_even_if_previously_assigned
 * below for the corrected behavior.)
 */
class ProviderSkillAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeProvider($franchise, $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active',
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    private function makeCategory(string $name, bool $active = true): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'module' => 'service', 'is_active' => $active,
        ]);
    }

    public function test_franchise_scoped_review_kyc_actor_can_assign_valid_categories(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise, $zone);
        $catA = $this->makeCategory('Appliance');
        $catB = $this->makeCategory('Electrical');

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.review_kyc', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('skillsInput', [$catA->id, $catB->id])
            ->call('updateSkills')
            ->assertSet('flashType', 'success');

        $this->assertEqualsCanonicalizing([$catA->id, $catB->id], $provider->fresh()->skills);
    }

    public function test_duplicate_submitted_ids_are_deduplicated(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise, $zone);
        $cat = $this->makeCategory('Appliance');

        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $franchise->id);
        $this->grantPermission($actor, 'providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('skillsInput', [$cat->id, $cat->id])
            ->call('updateSkills');

        $this->assertSame([$cat->id], $provider->fresh()->skills);
    }

    public function test_nonexistent_category_ids_are_dropped(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise, $zone);
        $real = $this->makeCategory('Appliance');
        $bogusId = $real->id + 999999;

        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $franchise->id);
        $this->grantPermission($actor, 'providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('skillsInput', [$real->id, $bogusId])
            ->call('updateSkills');

        $this->assertSame([$real->id], $provider->fresh()->skills);
    }

    public function test_inactive_category_ids_are_dropped_even_if_previously_assigned(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise, $zone);
        $active = $this->makeCategory('Appliance');
        $inactive = $this->makeCategory('Retired', active: false);

        // Simulate a category that was validly assigned before being
        // deactivated -- the column has no DB-level constraint tying it to
        // is_active, so this state is reachable in production, not just a
        // contrived starting point.
        $provider->update(['skills' => [$active->id, $inactive->id]]);

        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $franchise->id);
        $this->grantPermission($actor, 'providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('skillsInput', [$active->id, $inactive->id])
            ->call('updateSkills')
            ->assertSet('flashType', 'success');

        $this->assertSame([$active->id], $provider->fresh()->skills);
    }

    public function test_actor_with_only_providers_view_cannot_assign_skills(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise, $zone);
        $cat = $this->makeCategory('Appliance');

        // providers.view is enough to mount the screen (mount()'s own gate)
        // but not enough to call updateSkills(), gated separately by
        // canReview() -> providers.review_kyc.
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('skillsInput', [$cat->id])
            ->call('updateSkills')
            ->assertSet('flashType', 'error');

        $this->assertSame([], $provider->fresh()->skills ?? []);
    }

    public function test_review_kyc_scoped_to_a_different_franchise_cannot_assign_skills(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchiseA, $zoneA);
        $cat = $this->makeCategory('Appliance');

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.review_kyc', 'franchise', $franchiseB->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('skillsInput', [$cat->id])
            ->call('updateSkills')
            ->assertSet('flashType', 'error');

        $this->assertSame([], $provider->fresh()->skills ?? []);
    }

    public function test_actor_without_providers_view_cannot_even_mount_the_screen(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise, $zone);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])->assertForbidden();
    }
}
