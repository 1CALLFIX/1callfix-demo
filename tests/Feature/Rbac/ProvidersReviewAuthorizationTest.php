<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Providers\Show;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a real, live gap found this session (see
 * EXACT_NEXT_TASK.md): Providers\Show::updatePriority() has always checked
 * providers.review_kyc, but approve()/reject() never did — any authenticated
 * admin-panel actor, regardless of role or scope, could approve or reject a
 * provider's KYC with zero permission check. Workers\Show (built later,
 * covering the equivalent field-worker screen) already had this check —
 * Providers\Show was the one left behind. Fixed by extracting the shared
 * canReview() check (mirroring Workers\Show::canReview() exactly) and
 * guarding approve()/reject() with it, in the same commit as this test.
 *
 * Providers\Show reports authorization failures via its own flashType/
 * flashMessage properties, not Livewire's addError()/$errors bag (unlike
 * Franchises\Manage etc. in this same suite) — assertions here match that
 * actual component behavior rather than the addError() pattern used
 * elsewhere in this directory.
 */
class ProvidersReviewAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function makeProvider(?int $franchiseId = null, ?int $zoneId = null): Provider
    {
        $franchise = $franchiseId ? null : $this->makeFranchise();

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Under Review',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider',
            'status' => 'active',
            'franchise_id' => $franchiseId ?? $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id,
            'franchise_id' => $franchiseId ?? $franchise->id,
            'zone_id' => $zoneId,
            'provider_type' => 'independent',
            'kyc_status' => 'pending',
            'is_active' => true,
        ]);
    }

    public function test_approve_denied_without_permission(): void
    {
        $provider = $this->makeProvider();
        $actor = $this->makeUserWithNoPermissions();

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('approve');

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $provider->fresh()->kyc_status);
    }

    public function test_reject_denied_without_permission(): void
    {
        $provider = $this->makeProvider();
        $actor = $this->makeUserWithNoPermissions();

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->set('rejectionReason', 'Incomplete documents')
            ->call('reject');

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $provider->fresh()->kyc_status);
    }

    public function test_approve_allowed_with_correct_franchise_scope(): void
    {
        $provider = $this->makeProvider();
        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $provider->franchise_id);

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('approve');

        $this->assertSame('success', $component->get('flashType'));
        $this->assertSame('approved', $provider->fresh()->kyc_status);
    }

    public function test_wrong_franchise_scope_is_denied(): void
    {
        $ownFranchise = $this->makeFranchise();
        $provider = $this->makeProvider();
        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $ownFranchise->id);

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('approve');

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $provider->fresh()->kyc_status);
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(Show::class, ['providerId' => $provider->id])
            ->call('approve');

        $this->assertSame('success', $component->get('flashType'));
        $this->assertSame('approved', $provider->fresh()->kyc_status);
    }

    public function test_update_priority_still_denied_without_permission_unchanged_behavior(): void
    {
        $provider = $this->makeProvider();
        $actor = $this->makeUserWithNoPermissions();

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->set('priorityInput', '5')
            ->call('updatePriority');

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame(0, $provider->fresh()->priority);
    }
}
