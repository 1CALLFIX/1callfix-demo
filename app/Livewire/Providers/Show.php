<?php

namespace App\Livewire\Providers;

use App\Actions\ReviewProviderKycAction;
use App\Models\Provider;
use Livewire\Component;

class Show extends Component
{
    public Provider $provider;
    public string $rejectionReason = '';
    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(int $providerId)
    {
        $this->provider = Provider::with(['user', 'zone', 'franchise', 'documents'])
            ->findOrFail($providerId);
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
