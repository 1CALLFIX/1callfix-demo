<?php

namespace Tests\Feature\Providers;

use App\Actions\ReviewProviderKycAction;
use App\Livewire\Providers\Show;
use App\Models\Provider;
use App\Models\ProviderDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Live gap closed this session: ReviewProviderKycAction::approve() gates on
 * every required KYC document already carrying an `approved`, `is_current`
 * row (KycDocumentService::missingApprovedRequirements()), but no admin
 * screen could set an individual document's status — so no provider,
 * self-registered or CSV-imported, could ever be approved. Providers\Show
 * now exposes approveDocument()/rejectDocument(); this test proves the full
 * chain (approve each document → approve the provider) works, and that the
 * provider-level gate still correctly refuses while any required document
 * is rejected or still pending.
 */
class ProviderDocumentReviewTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    /** The default global requirement set (migration 2026_08_14_021000). */
    private const REQUIRED_TYPES = ['id_proof', 'address_proof', 'bank_details'];

    private function makePendingProvider(): Provider
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending']);

        return $provider->fresh();
    }

    /** One pending, is_current document row per given type. */
    private function seedPendingDocuments(Provider $provider, array $types = self::REQUIRED_TYPES): void
    {
        foreach ($types as $type) {
            ProviderDocument::create([
                'provider_id' => $provider->id,
                'type' => $type,
                'disk_path' => "kyc/providers/{$provider->id}/{$type}/f.pdf",
                'status' => 'pending',
                'is_current' => true,
            ]);
        }
    }

    private function reviewer(Provider $provider): \App\Models\User
    {
        $actor = $this->makeUserWithPermission('providers.review_kyc', 'franchise', $provider->franchise_id);
        $this->grantPermission($actor, 'providers.view');

        return $actor;
    }

    public function test_admin_approves_each_document_then_approves_the_provider(): void
    {
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider);
        $actor = $this->reviewer($provider);

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id]);

        foreach ($provider->currentDocuments as $doc) {
            $component->call('approveDocument', $doc->id);
        }

        foreach ($provider->currentDocuments()->get() as $doc) {
            $this->assertSame('approved', $doc->status);
            $this->assertSame($actor->id, $doc->reviewed_by);
            $this->assertNotNull($doc->reviewed_at);
        }

        // The gate is now satisfied — the provider-level approval succeeds
        // with the unchanged ReviewProviderKycAction.
        $component->call('approve');

        $this->assertSame('success', $component->get('flashType'));
        $this->assertSame('approved', $provider->fresh()->kyc_status);
    }

    public function test_provider_approval_still_refused_while_a_document_is_rejected(): void
    {
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider);
        $actor = $this->reviewer($provider);

        $byType = $provider->currentDocuments()->get()->keyBy('type');

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id]);

        $component->call('approveDocument', $byType['id_proof']->id);
        $component->call('approveDocument', $byType['address_proof']->id);
        $component
            ->set("documentRejectionReason.{$byType['bank_details']->id}", 'Bank statement is illegible')
            ->call('rejectDocument', $byType['bank_details']->id);

        $this->assertSame('rejected', $byType['bank_details']->fresh()->status);
        $this->assertSame('Bank statement is illegible', $byType['bank_details']->fresh()->rejection_reason);

        $component->call('approve');

        $this->assertSame('error', $component->get('flashType'));
        $this->assertStringContainsString('bank_details', $component->get('flashMessage'));
        $this->assertSame('pending', $provider->fresh()->kyc_status);
    }

    public function test_provider_approval_still_refused_while_a_document_is_pending(): void
    {
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider);
        $actor = $this->reviewer($provider);
        $docs = $provider->currentDocuments()->get();

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id]);
        $component->call('approveDocument', $docs[0]->id);
        $component->call('approveDocument', $docs[1]->id);
        // docs[2] left pending

        $component->call('approve');

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $provider->fresh()->kyc_status);
        $this->assertSame('pending', $docs[2]->fresh()->status);
    }

    public function test_rejecting_a_document_requires_a_reason(): void
    {
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider, ['id_proof']);
        $actor = $this->reviewer($provider);
        $doc = $provider->currentDocuments()->first();

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('rejectDocument', $doc->id);

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $doc->fresh()->status);
    }

    public function test_document_review_denied_without_review_permission(): void
    {
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider, ['id_proof']);
        $actor = $this->makeUserWithNoPermissions();
        $this->grantPermission($actor, 'providers.view');
        $doc = $provider->currentDocuments()->first();

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('approveDocument', $doc->id);

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $doc->fresh()->status);
        $this->assertNull($doc->fresh()->reviewed_by);
    }

    public function test_cannot_review_a_document_belonging_to_another_provider(): void
    {
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider, ['id_proof']);

        $other = $this->makePendingProvider();
        $this->seedPendingDocuments($other, ['id_proof']);
        $foreignDoc = $other->currentDocuments()->first();

        // A reviewer legitimately scoped to the first provider's franchise.
        $actor = $this->reviewer($provider);

        $component = Livewire::actingAs($actor)->test(Show::class, ['providerId' => $provider->id])
            ->call('approveDocument', $foreignDoc->id);

        $this->assertSame('error', $component->get('flashType'));
        $this->assertSame('pending', $foreignDoc->fresh()->status);
    }

    public function test_reviewing_documents_directly_unblocks_the_action_layer(): void
    {
        // Same outcome as the Livewire happy path, asserted one layer down:
        // once each required document row is `approved`, the unchanged
        // ReviewProviderKycAction::approve() clears its own gate.
        $provider = $this->makePendingProvider();
        $this->seedPendingDocuments($provider);

        $provider->currentDocuments()->update(['status' => 'approved']);

        $result = app(ReviewProviderKycAction::class)->approve($provider->id);

        $this->assertSame('approved', $result->kyc_status);
    }
}
