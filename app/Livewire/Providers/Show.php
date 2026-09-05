<?php

namespace App\Livewire\Providers;

use App\Actions\ReviewProviderKycAction;
use App\Actions\SetProviderCommissionAgreementAction;
use App\Models\Provider;
use App\Models\ServiceCategory;
use App\Services\AuthorizationService;
use App\Services\ProviderCommercialRateResolver;
use Illuminate\Support\Str;
use Livewire\Component;

class Show extends Component
{
    public Provider $provider;
    public string $rejectionReason = '';
    public string $priorityInput = '0';
    public string $flashMessage = '';
    public string $flashType = 'success';

    /**
     * Per-document rejection reasons, keyed by provider_documents.id. A
     * reviewer types a reason into a document card before clicking "Reject"
     * on that card. Client-editable Livewire state — the id is re-checked
     * against this provider's own current documents in reviewDocument().
     */
    public array $documentRejectionReason = [];

    // --- Delete confirmation (Tier 1 CRUD audit -- Provider had no delete
    // action anywhere in the admin UI, despite the model already using
    // SoftDeletes) ---
    public bool $confirmingDelete = false;
    public string $deleteWarning = '';

    /**
     * Skills = the category IDs DispatchService::hasSkill() checks a
     * provider against for real dispatch eligibility — until now this
     * screen only ever displayed the raw array read-only (see the
     * "Skills (category IDs)" line below), with no admin action anywhere
     * that could ever set it. That left "assign a provider to a
     * category/service" a real gap: bulk pre-register creates the account
     * shell with no skills, and nothing else wrote to this column outside
     * QaSeeder. Checkbox list of every active ServiceCategory, keyed by id.
     */
    public array $skillsInput = [];

    // --- Commercial rate (Provider Commercial Rate Resolver, tier 1:
    // negotiated agreement) — the form for setting/clearing this specific
    // provider's negotiated platform_fee_percent. ---
    public string $commissionPercentInput = '';
    public string $commissionNotesInput = '';

    /** providers.view was seeded (2026_08_11_016000) but never checked on this detail screen (only approve/reject/updatePriority were gated, via canReview()'s providers.review_kyc) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(int $providerId)
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('providers.view'), 403, 'You do not have permission to view providers.');

        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        // ->first() + abort_if(), not findOrFail() -- see Bookings\Show::mount()'s
        // identical comment for why ModelNotFoundException isn't safe here.
        $provider = app(AuthorizationService::class)
            ->scopeQuery(Provider::query(), auth()->user(), 'providers.view', $columns)
            ->with(['user', 'zone', 'franchise.country', 'documents', 'commissionAgreement'])
            ->find($providerId);

        abort_if(! $provider, 404);

        $this->provider = $provider;
        $this->priorityInput = (string) $this->provider->priority;
        $this->skillsInput = array_map('intval', $this->provider->skills ?? []);
        $this->commissionPercentInput = (string) ($this->provider->commissionAgreement->platform_fee_percent ?? '');
        $this->commissionNotesInput = (string) ($this->provider->commissionAgreement->notes ?? '');
    }

    /**
     * Shared "can manage this provider" check — providers.review_kyc,
     * scope-checked against the provider's own franchise/zone. Reused by
     * updatePriority()/approve()/reject() so all three enforce the same
     * boundary the same way. Mirrors Workers\Show::canReview() exactly —
     * that screen was built after this one and got this check; this one
     * didn't, until now (approve()/reject() had NO authorization check at
     * all, so any authenticated admin user, regardless of role/scope,
     * could approve or reject KYC for a provider outside their scope, or
     * with no providers.review_kyc permission whatsoever).
     */
    private function canReview(): bool
    {
        return auth()->user()->hasPermission('providers.review_kyc', array_filter([
            'zone_id' => $this->provider->zone_id,
            'franchise_id' => $this->provider->franchise_id,
        ]));
    }

    /**
     * Manual ranking priority — the criterion RankingEngine reads when
     * "priority" is included in the configured ranking rule (Settings >
     * Ranking).
     */
    public function updatePriority(): void
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to change this provider\'s priority.';
            return;
        }

        $this->validate(['priorityInput' => ['required', 'integer', 'min:0', 'max:1000']], [], ['priorityInput' => 'priority']);

        $this->provider->update(['priority' => (int) $this->priorityInput]);
        $this->flashType = 'success';
        $this->flashMessage = 'Priority updated.';
    }

    /**
     * The actual "assign this provider to a category/service" action —
     * writes the category IDs DispatchService::hasSkill() checks a
     * provider's `skills` array against for real dispatch eligibility.
     * Same canReview() boundary as updatePriority() above: no dedicated
     * permission exists for this yet, and this is the established
     * "can manage this specific provider" check on this screen.
     */
    public function updateSkills(): void
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to change this provider\'s assigned categories.';
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $this->skillsInput)));

        // Silently drop any id that isn't a real, still-active category —
        // the checkbox list (render()'s own `categories` query) only ever
        // offers active ones, but the array is client-editable Livewire
        // state, not a trusted source on its own. Matches render()'s filter
        // exactly (`is_active = true`) so a category deactivated after being
        // assigned drops out on the next save rather than lingering forever.
        $validIds = ServiceCategory::whereIn('id', $ids)->where('is_active', true)->pluck('id')->all();

        $this->provider->update(['skills' => $validIds]);
        $this->skillsInput = $validIds;
        $this->flashType = 'success';
        $this->flashMessage = 'Assigned categories updated.';
    }

    /**
     * providers.manage -- the same permission Providers\Index's Bulk
     * Pre-Register and ProviderPreRegisterImporter already gate on,
     * scope-checked against this provider's own zone/franchise exactly
     * like canReview() does for providers.review_kyc above. Reused here
     * rather than inventing a new permission slug, matching how
     * Categories/Subcategories/Services all reuse their screen's single
     * ".manage" permission for delete too.
     */
    private function canDelete(): bool
    {
        return auth()->user()->hasPermission('providers.manage', array_filter([
            'zone_id' => $this->provider->zone_id,
            'franchise_id' => $this->provider->franchise_id,
        ]));
    }

    /**
     * Soft delete only -- Provider already uses SoftDeletes; never
     * forceDelete() here. Most FK columns pointing at providers.id are
     * cascadeOnDelete (provider_documents, reviews, kyc_verification_videos,
     * properties, stores, vehicles, equipment_items, accommodations,
     * partner_workers, booking_compensations, dispatch_attempts, ...) but
     * those only fire on a real row deletion, never on a soft delete's
     * deleted_at update -- so this is inherently non-destructive to that
     * whole history, mirrors Services\Manage::deleteService()'s "warn,
     * don't block" posture exactly (not Categories/Subcategories' hard
     * delete + hard block), and stays trivially reversible. payouts.payee_id
     * is an unconstrained polymorphic-style column (no FK at all for
     * payee_type='provider' rows) so it's untouched either way.
     */
    public function confirmDelete(): void
    {
        if (! $this->canDelete()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to delete this provider.';
            return;
        }

        $bookingsCount = $this->provider->bookings()->count();
        $technicianCount = $this->provider->technicians()->count();

        $warnings = [];
        if ($bookingsCount > 0) {
            $warnings[] = $bookingsCount.' '.Str::plural('booking', $bookingsCount).' against it';
        }
        if ($technicianCount > 0) {
            $warnings[] = $technicianCount.' '.Str::plural('technician', $technicianCount).' linked under it';
        }
        if ($this->provider->is_online) {
            $warnings[] = 'currently online';
        }

        $this->deleteWarning = $warnings
            ? 'This provider has '.implode(', ', $warnings).'. It will be hidden from dispatch and admin lists, and that history stays intact.'
            : '';
        $this->confirmingDelete = true;
    }

    public function deleteProvider(): void
    {
        if (! $this->confirmingDelete) {
            return;
        }

        if (! $this->canDelete()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to delete this provider.';
            $this->confirmingDelete = false;
            return;
        }

        $this->provider->delete();

        $this->confirmingDelete = false;
        $this->deleteWarning = '';
        $this->flashType = 'success';
        $this->flashMessage = 'Provider deleted.';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->deleteWarning = '';
    }

    // ==================== Commercial rate (negotiated agreement) ====================

    /**
     * Same permission slug + scope shape as canDelete() above — this screen
     * has no dedicated "commission" permission, and providers.manage is
     * already the "can change this provider's operational configuration"
     * boundary the rest of this screen uses for exactly this kind of edit.
     */
    private function canManageCommission(): bool
    {
        return auth()->user()->hasPermission('providers.manage', array_filter([
            'zone_id' => $this->provider->zone_id,
            'franchise_id' => $this->provider->franchise_id,
        ]));
    }

    public function setCommissionAgreement(SetProviderCommissionAgreementAction $action): void
    {
        if (! $this->canManageCommission()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to set this provider\'s commercial rate.';
            return;
        }

        $this->validate([
            'commissionPercentInput' => ['required', 'numeric', 'min:0', 'max:100'],
            'commissionNotesInput' => ['nullable', 'string', 'max:1000'],
        ], [], ['commissionPercentInput' => 'negotiated platform fee', 'commissionNotesInput' => 'notes']);

        $action->set(
            $this->provider,
            (float) $this->commissionPercentInput,
            $this->commissionNotesInput ?: null,
            auth()->user(),
        );

        $this->provider->load('commissionAgreement');
        $this->flashType = 'success';
        $this->flashMessage = 'Negotiated commercial rate saved.';
    }

    public function clearCommissionAgreement(SetProviderCommissionAgreementAction $action): void
    {
        if (! $this->canManageCommission()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to change this provider\'s commercial rate.';
            return;
        }

        $action->clear($this->provider, auth()->user());

        $this->provider->load('commissionAgreement');
        $this->commissionPercentInput = '';
        $this->commissionNotesInput = '';
        $this->flashType = 'success';
        $this->flashMessage = 'Negotiated rate cleared — this provider now inherits the franchise/global default.';
    }

    /**
     * Per-document KYC review — the missing piece that made "Approve
     * Provider" unreachable. ReviewProviderKycAction::approve() gates on
     * KycDocumentService::missingApprovedRequirements() being empty, i.e.
     * every required document type already carrying an `approved`,
     * `is_current` row. Nothing in the admin UI could set an individual
     * document's status, so no provider — self-registered or CSV-imported —
     * could ever clear that gate. These two actions are the whole fix:
     * they move one provider_documents row pending → approved/rejected,
     * same canReview() boundary as approve()/reject() below.
     *
     * Only offered while the provider itself is still `pending` — once a
     * KYC decision is made, ReviewProviderKycAction has already swept every
     * remaining current document to match (approve()/reject()), so the
     * per-document controls have nothing left to do (mirrors the
     * `kyc_status === 'pending'` guard the Approve/Reject cards use).
     */
    public function approveDocument(int $documentId): void
    {
        $this->reviewDocument($documentId, 'approved');
    }

    public function rejectDocument(int $documentId): void
    {
        $reason = trim($this->documentRejectionReason[$documentId] ?? '');

        if ($reason === '') {
            $this->flashType = 'error';
            $this->flashMessage = 'Enter a reason before rejecting a document.';
            return;
        }

        $this->reviewDocument($documentId, 'rejected', $reason);
    }

    private function reviewDocument(int $documentId, string $status, ?string $reason = null): void
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to review this provider\'s documents.';
            return;
        }

        // The id comes from client-editable Livewire state — scope the
        // lookup to THIS provider's own current documents so a reviewer can
        // never flip a row that isn't in front of them.
        $document = $this->provider->documents()->where('is_current', true)->find($documentId);

        if (! $document) {
            $this->flashType = 'error';
            $this->flashMessage = 'That document is no longer available for review.';
            return;
        }

        $document->update([
            'status' => $status,
            'rejection_reason' => $status === 'rejected' ? $reason : null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        unset($this->documentRejectionReason[$documentId]);
        $this->provider->load(['documents', 'currentDocuments']);

        $this->flashType = 'success';
        $this->flashMessage = 'Document marked as '.$status.'.';
    }

    public function approve(ReviewProviderKycAction $action)
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to review this provider.';
            return;
        }

        try {
            $this->provider = $action->approve($this->provider->id);
            $this->flashType = 'success';
            $this->flashMessage = 'Provider approved. They can now go online and receive job offers.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function reject(ReviewProviderKycAction $action)
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to review this provider.';
            return;
        }

        if (!$this->rejectionReason) {
            $this->flashType = 'error';
            $this->flashMessage = 'Enter a reason for rejection.';
            return;
        }

        $this->provider = $action->reject($this->provider->id, $this->rejectionReason);
        $this->provider->load('documents');
        $this->flashType = 'success';
        $this->flashMessage = 'Provider rejected.';
        $this->rejectionReason = '';
    }

    public function render()
    {
        $explanation = app(\App\Services\Kyc\KycWithdrawalPolicyService::class)->explain($this->provider, array_filter([
            'zone_id' => $this->provider->zone_id,
            'franchise_id' => $this->provider->franchise_id,
        ]));

        $rateResolver = app(ProviderCommercialRateResolver::class);

        return view('livewire.providers.show', [
            'withdrawalRestricted' => $explanation['restricted'],
            'withdrawalReason' => $explanation['reason'],
            'effectiveCommissionPercent' => $this->provider->franchise
                ? $rateResolver->resolve($this->provider->franchise, $this->provider)
                : null,
            'effectiveCommissionTier' => $this->provider->franchise
                ? $rateResolver->resolvedTier($this->provider->franchise, $this->provider)
                : null,
            // Phase 13 (Glover/6amMart parity audit) — reviews had a
            // rating_avg field shown on this screen since Phase 1 but no
            // real review ever existed to produce one (see
            // ReviewService's docblock). Now that a write path exists,
            // surface the actual rows here too, not just the rollup.
            'recentReviews' => $this->provider->reviews()->with('customer')->latest()->limit(10)->get(),
            'categories' => ServiceCategory::where('is_active', true)->orderBy('module')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'module']),
        ])->layout('layouts.admin', ['title' => 'Review Provider']);
    }
}
