<?php

namespace App\Livewire\Providers;

use App\Actions\ReviewProviderKycAction;
use App\Models\Provider;
use App\Services\AuthorizationService;
use Livewire\Component;

class Show extends Component
{
    public Provider $provider;
    public string $rejectionReason = '';
    public string $priorityInput = '0';
    public string $flashMessage = '';
    public string $flashType = 'success';

    /** providers.view was seeded (2026_08_11_016000) but never checked on this detail screen (only approve/reject/updatePriority were gated, via canReview()'s providers.review_kyc) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(int $providerId)
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('providers.view'), 403, 'You do not have permission to view providers.');

        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        // ->first() + abort_if(), not findOrFail() -- see Bookings\Show::mount()'s
        // identical comment for why ModelNotFoundException isn't safe here.
        $provider = app(AuthorizationService::class)
            ->scopeQuery(Provider::query(), auth()->user(), 'providers.view', $columns)
            ->with(['user', 'zone', 'franchise', 'documents'])
            ->find($providerId);

        abort_if(! $provider, 404);

        $this->provider = $provider;
        $this->priorityInput = (string) $this->provider->priority;
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

        return view('livewire.providers.show', [
            'withdrawalRestricted' => $explanation['restricted'],
            'withdrawalReason' => $explanation['reason'],
            // Phase 13 (Glover/6amMart parity audit) — reviews had a
            // rating_avg field shown on this screen since Phase 1 but no
            // real review ever existed to produce one (see
            // ReviewService's docblock). Now that a write path exists,
            // surface the actual rows here too, not just the rollup.
            'recentReviews' => $this->provider->reviews()->with('customer')->latest()->limit(10)->get(),
        ])->layout('layouts.admin', ['title' => 'Review Provider']);
    }
}
