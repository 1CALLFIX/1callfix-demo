<?php

namespace App\Livewire\Workers;

use App\Actions\ReviewFieldWorkerKycAction;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Support\WorkerTypes;
use Livewire\Component;

/** Mirrors Providers\Show exactly (KYC approve/reject), plus B0.1's capability model management — the piece B0.1/B0.2 built and tested end-to-end but never gave an admin screen. */
class Show extends Component
{
    public FieldWorker $fieldWorker;
    public string $rejectionReason = '';
    public string $flashMessage = '';
    public string $flashType = 'success';

    public string $newCapabilityType = 'service_technician';
    public ?int $newCapabilityServiceCategoryId = null;

    public function mount(int $workerId)
    {
        $this->fieldWorker = FieldWorker::with(['user', 'zone', 'franchise', 'documents', 'capabilities.serviceCategory', 'partnerLinks.provider.user'])
            ->findOrFail($workerId);
    }

    public function workerTypeOptions(): array
    {
        return WorkerTypes::ALL;
    }

    private function canReview(): bool
    {
        return auth()->user()->hasPermission('workers.review_kyc', array_filter([
            'zone_id' => $this->fieldWorker->zone_id,
            'franchise_id' => $this->fieldWorker->franchise_id,
        ]));
    }

    public function approve(ReviewFieldWorkerKycAction $action)
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to review this worker.';
            return;
        }

        $this->fieldWorker = $action->approve($this->fieldWorker->id);
        $this->fieldWorker->load('documents');
        $this->flashType = 'success';
        $this->flashMessage = 'Worker approved. They can now go online and be assigned jobs.';
    }

    public function reject(ReviewFieldWorkerKycAction $action)
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to review this worker.';
            return;
        }

        if (! $this->rejectionReason) {
            $this->flashType = 'error';
            $this->flashMessage = 'Enter a reason for rejection.';
            return;
        }

        $this->fieldWorker = $action->reject($this->fieldWorker->id, $this->rejectionReason);
        $this->fieldWorker->load('documents');
        $this->flashType = 'success';
        $this->flashMessage = 'Worker rejected.';
        $this->rejectionReason = '';
    }

    public function toggleActive()
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to change this worker\'s status.';
            return;
        }

        $this->fieldWorker->is_active = ! $this->fieldWorker->is_active;
        $this->fieldWorker->save();
        $this->flashType = 'success';
        $this->flashMessage = $this->fieldWorker->is_active ? 'Worker activated.' : 'Worker deactivated.';
    }

    public function addCapability()
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this worker\'s capabilities.';
            return;
        }

        $this->validate([
            'newCapabilityType' => ['required', 'in:' . implode(',', WorkerTypes::slugs())],
        ]);

        $alreadyHas = $this->fieldWorker->capabilities()
            ->where('capability_type', $this->newCapabilityType)
            ->where('service_category_id', $this->newCapabilityServiceCategoryId)
            ->exists();

        if ($alreadyHas) {
            $this->flashType = 'error';
            $this->flashMessage = 'This worker already has that exact capability.';
            return;
        }

        FieldWorkerCapability::create([
            'field_worker_id' => $this->fieldWorker->id,
            'capability_type' => $this->newCapabilityType,
            'service_category_id' => $this->newCapabilityServiceCategoryId,
        ]);

        $this->fieldWorker->load('capabilities.serviceCategory');
        $this->newCapabilityServiceCategoryId = null;
        $this->flashType = 'success';
        $this->flashMessage = 'Capability added.';
    }

    public function removeCapability(int $capabilityId)
    {
        if (! $this->canReview()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this worker\'s capabilities.';
            return;
        }

        FieldWorkerCapability::where('id', $capabilityId)
            ->where('field_worker_id', $this->fieldWorker->id)
            ->delete();

        $this->fieldWorker->load('capabilities.serviceCategory');
        $this->flashType = 'success';
        $this->flashMessage = 'Capability removed.';
    }

    public function render()
    {
        return view('livewire.workers.show')
            ->layout('layouts.admin', ['title' => 'Review Worker']);
    }
}
