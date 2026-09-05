<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Show as ProvidersShow;
use App\Models\ProviderCommissionAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider Commercial Rate Resolver phase — Step 10 D. Providers\Show's new
 * "Commercial Rate" section: set/clear a negotiated agreement, gated the
 * same providers.manage + zone/franchise scope shape as canDelete() (this
 * screen's existing "can manage this provider" boundary for non-KYC edits),
 * same pattern ProviderSkillAssignmentTest already established for this
 * screen's providers.review_kyc-gated actions.
 */
class ProvidersShowCommercialRateTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_providers_manage_actor_can_set_a_negotiated_rate(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('commissionPercentInput', '20')
            ->set('commissionNotesInput', 'Negotiated volume rate')
            ->call('setCommissionAgreement')
            ->assertSet('flashType', 'success');

        $agreement = ProviderCommissionAgreement::where('provider_id', $provider->id)->first();
        $this->assertNotNull($agreement);
        $this->assertEquals(20.0, $agreement->platform_fee_percent);
    }

    public function test_actor_with_only_providers_view_cannot_set_a_negotiated_rate(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('commissionPercentInput', '20')
            ->call('setCommissionAgreement')
            ->assertSet('flashType', 'error');

        $this->assertFalse(ProviderCommissionAgreement::where('provider_id', $provider->id)->exists());
    }

    public function test_providers_manage_scoped_to_a_different_franchise_cannot_set_a_rate(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchiseA, $zoneA);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchiseB->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('commissionPercentInput', '20')
            ->call('setCommissionAgreement')
            ->assertSet('flashType', 'error');

        $this->assertFalse(ProviderCommissionAgreement::where('provider_id', $provider->id)->exists());
    }

    public function test_out_of_range_percent_is_rejected(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->set('commissionPercentInput', '150')
            ->call('setCommissionAgreement')
            ->assertHasErrors(['commissionPercentInput']);

        $this->assertFalse(ProviderCommissionAgreement::where('provider_id', $provider->id)->exists());
    }

    public function test_providers_manage_actor_can_clear_an_existing_agreement(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        ProviderCommissionAgreement::create(['provider_id' => $provider->id, 'platform_fee_percent' => 18]);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('clearCommissionAgreement')
            ->assertSet('flashType', 'success')
            ->assertSet('commissionPercentInput', '');

        $this->assertFalse(ProviderCommissionAgreement::where('provider_id', $provider->id)->exists());
    }

    public function test_the_screen_shows_the_effective_resolved_rate_and_tier(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree(); // platform_fee_percent = 5
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->assertViewHas('effectiveCommissionPercent', 5.0)
            ->assertViewHas('effectiveCommissionTier', 'franchise');
    }
}
