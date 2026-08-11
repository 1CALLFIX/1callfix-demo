<?php

namespace App\Livewire\Providers;

use App\Actions\ReviewProviderKycAction;
use App\Models\Provider;
use Livewire\Component;

class Show extends Component
{
    public Provider $provider;
    public string $rejectionReason = '';
    public string $priorityInput = '0';
    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(int $providerId)
    {
        $this->provider = Provider::with(['user', 'zone', 'franchise', 'documents'])
            ->findOrFail($providerId);
        $this->priorityInput = (string) $this->provider->priority;
    }

    /**
     * Manual ranking priority — the criterion RankingEngine reads when
     * "priority" is included in the configured ranking rule (Settings >
     * Ranking). Reuses providers.review_kyc (the existing "can manage this
     * provider" permission) rather than a new one, scope-checked against
     * the provider's own franchise.
     */
    public function updatePriority(): void
    {
        if (! auth()->user()->hasPermission('providers.review_kyc', array_filter([
            'zone_id' => $this->provider->zone_id,
            'franchise_id' => $this->provider->franchise_id,
        ]))) {
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
        $this->provider = $action->approve($this->provider->id);
        $this->flashType = 'success';
        $this->flashMessage = 'Provider approved. They can now go online and receive job offers.';
    }

    public function reject(ReviewProviderKycAction $action)
    {
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
        return view('livewire.providers.show')
            ->layout('layouts.admin', ['title' => 'Review Provider']);
    }
}
