<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Show;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Tier 1 CRUD audit follow-up -- Provider had no delete action anywhere in
 * the admin UI (confirmed by a full-codebase read of Providers\Index and
 * Providers\Show), despite the model already using SoftDeletes. Closes that
 * gap: soft delete only (never forceDelete -- see Providers\Show::
 * confirmDelete()'s own docblock for the full FK-cascade reasoning), the
 * providers.manage permission boundary (reused from Providers\Index's Bulk
 * Pre-Register, scope-checked against the provider's own zone/franchise
 * exactly like canReview()'s providers.review_kyc boundary), the warning
 * text for a provider with real dependents, and -- the actual point of
 * choosing soft delete over hard delete -- that related rows (documents,
 * etc.) are genuinely left untouched, not silently cascaded away.
 */
class ProviderDeleteTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeProvider(?int $franchiseId = null, ?int $zoneId = null): Provider
    {
        $franchise = $franchiseId ? null : $this->makeFranchise();

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider Under Test',
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

    public function test_franchise_scoped_providers_manage_actor_can_delete_a_provider(): void
    {
        $provider = $this->makeProvider();

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete')
            ->assertSet('confirmingDelete', true)
            ->call('deleteProvider')
            ->assertSet('confirmingDelete', false)
            ->assertSet('flashType', 'success');

        $this->assertSoftDeleted('providers', ['id' => $provider->id]);
    }

    public function test_delete_is_soft_only_and_leaves_related_rows_intact(): void
    {
        $provider = $this->makeProvider();
        $document = ProviderDocument::create([
            'provider_id' => $provider->id, 'type' => 'id_proof', 'file_url' => 'legacy',
            'disk_path' => "kyc/providers/{$provider->id}/id_proof/f.pdf", 'status' => 'approved', 'is_current' => true,
        ]);

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete')
            ->call('deleteProvider');

        // The whole point of soft delete over hard delete here: provider_documents.provider_id
        // is cascadeOnDelete at the DB level, but that FK only fires on a real
        // row deletion -- a soft delete (deleted_at set) must never trigger it.
        $this->assertSoftDeleted('providers', ['id' => $provider->id]);
        $this->assertDatabaseHas('provider_documents', ['id' => $document->id]);
    }

    public function test_delete_warning_mentions_bookings_technicians_and_online_status(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProvider($franchise->id, $zone->id);
        $provider->update(['is_online' => true]);
        $technician = $this->makeProvider($franchise->id, $zone->id);
        $technician->update(['parent_provider_id' => $provider->id]);

        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'provider_id' => $provider->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'completed', 'price_quoted' => 500, 'payment_status' => 'paid', 'payment_method' => 'online',
        ]);

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete');

        $warning = $component->get('deleteWarning');
        $this->assertStringContainsString('1 booking', $warning);
        $this->assertStringContainsString('1 technician', $warning);
        $this->assertStringContainsString('currently online', $warning);
    }

    public function test_no_warning_for_a_provider_with_no_dependents(): void
    {
        $provider = $this->makeProvider();

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete')
            ->assertSet('deleteWarning', '');
    }

    public function test_cancel_delete_resets_state_without_deleting(): void
    {
        $provider = $this->makeProvider();

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete')
            ->assertSet('confirmingDelete', true)
            ->call('cancelDelete')
            ->assertSet('confirmingDelete', false)
            ->assertSet('deleteWarning', '');

        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'deleted_at' => null]);
    }

    public function test_actor_with_only_providers_view_cannot_delete(): void
    {
        $provider = $this->makeProvider();

        // providers.view is enough to mount the screen; providers.manage is
        // the separate permission confirmDelete()/deleteProvider() require.
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete')
            ->assertSet('confirmingDelete', false)
            ->assertSet('flashType', 'error');

        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'deleted_at' => null]);
    }

    public function test_providers_manage_scoped_to_a_different_franchise_cannot_delete(): void
    {
        $provider = $this->makeProvider();
        $otherFranchise = $this->makeFranchise();

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $otherFranchise->id);

        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('confirmDelete')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'deleted_at' => null]);
    }

    public function test_deleteProvider_is_a_noop_without_a_prior_confirmDelete_call(): void
    {
        $provider = $this->makeProvider();

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        // Calling deleteProvider() directly (e.g. a stale/replayed Livewire
        // request) without confirmDelete() having set confirmingDelete=true
        // first must not delete anything.
        Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('deleteProvider');

        $this->assertDatabaseHas('providers', ['id' => $provider->id, 'deleted_at' => null]);
    }
}
