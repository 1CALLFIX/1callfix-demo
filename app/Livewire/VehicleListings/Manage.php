<?php

namespace App\Livewire\VehicleListings;

use App\Livewire\Venue\VehicleEditor;
use App\Models\VehicleListing;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\Homes\ListingFeeService;
use App\Support\VehicleListingSettings;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Rental → Vehicles for rent (owner 2026-11-18, OLX-style). The team approves every owner vehicle before customers
 * see it, and every change to a live vehicle's photos, description, landmark or pickup address. Approve / Send back (with
 * a reason the owner sees) / Feature / Switch off. Row scope: `vehicle_listings.manage` on the vehicle's own zone /
 * franchise / city / country. Separate from Admin → Vehicles (the old admin catalog for the reservation engine).
 */
class Manage extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'in_review';

    public ?int $selectedId = null;

    public string $reason = '';

    private const PERMISSION = 'vehicle_listings.manage';

    private const COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere(self::PERMISSION), 403, 'You do not have permission to manage vehicles for rent.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->reset('selectedId', 'reason');
    }

    private function vehicles()
    {
        return app(AuthorizationService::class)->scopeQuery(VehicleListing::query(), auth()->user(), self::PERMISSION, self::COLUMNS);
    }

    private function find(int $id): VehicleListing
    {
        return $this->vehicles()->with('franchise')->find($id) ?? abort(404);
    }

    public function view(int $id): void
    {
        $this->find($id);
        $this->selectedId = $id;
        $this->reset('reason');
    }

    public function backToList(): void
    {
        $this->reset('selectedId', 'reason');
    }

    public function approve(int $id): void
    {
        $vehicle = $this->find($id);
        if ($vehicle->status !== 'in_review') {
            session()->flash('error', 'Only a vehicle waiting for review can be approved.');

            return;
        }
        $problems = VehicleEditor::submitProblems($vehicle);
        if ($problems !== []) {
            session()->flash('error', 'Not ready to approve: '.implode(' ', $problems));

            return;
        }
        $fee = ListingFeeService::required($vehicle);
        $vehicle->update(['status' => 'approved', 'approved_at' => now(), 'rejection_reason' => null, 'is_active' => true,
            'expires_at' => $fee ? now() : now()->addDays((int) VehicleListingSettings::get('vehicles.listing_days', $vehicle->scope()))]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle for rent approved (admin)');
        session()->flash('message', "{$vehicle->title} approved.".($fee ? ' It goes live when the owner pays the listing fee.' : ''));
    }

    public function sendBack(int $id): void
    {
        $vehicle = $this->find($id);
        if (! in_array($vehicle->status, ['in_review', 'approved'], true)) {
            session()->flash('error', 'This vehicle is not waiting for review.');

            return;
        }
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Tell the owner what to change.']);
        $vehicle->update(['status' => 'rejected', 'rejection_reason' => trim($this->reason), 'approved_at' => null, 'is_active' => false]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle for rent sent back for changes (admin)', ['reason' => trim($this->reason)]);
        $this->reset('reason');
        session()->flash('message', "{$vehicle->title} sent back to the owner with your reason.");
    }

    public function approveChanges(int $id): void
    {
        $vehicle = $this->find($id);
        $changes = (array) $vehicle->pending_changes;
        if ($changes === []) {
            return;
        }
        $vehicle->update(array_intersect_key($changes, array_flip(VehicleListing::REVIEWED)) + ['pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => null]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle change approved (admin)', ['fields' => array_keys($changes)]);
        session()->flash('message', "Change to {$vehicle->title} approved. Customers see it now.");
    }

    public function rejectChanges(int $id): void
    {
        $vehicle = $this->find($id);
        if ($vehicle->pending_changes === null) {
            return;
        }
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Tell the owner why.']);
        $vehicle->update(['pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => trim($this->reason)]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle change not accepted (admin)', ['reason' => trim($this->reason)]);
        $this->reset('reason');
        session()->flash('message', "Change to {$vehicle->title} not accepted. The owner sees your reason.");
    }

    public function toggleFeatured(int $id): void
    {
        $vehicle = $this->find($id);
        $vehicle->update(['is_featured' => ! $vehicle->is_featured]);
        ActivityLogger::logModel(auth()->user(), $vehicle, $vehicle->is_featured ? 'Vehicle featured (admin)' : 'Vehicle no longer featured (admin)');
    }

    public function toggleActive(int $id): void
    {
        $vehicle = $this->find($id);
        if ($vehicle->status !== 'approved') {
            return;
        }
        $vehicle->update(['is_active' => ! $vehicle->is_active]);
        ActivityLogger::logModel(auth()->user(), $vehicle, $vehicle->is_active ? 'Vehicle switched on (admin)' : 'Vehicle switched off (admin)');
    }

    public function render()
    {
        $data = [
            'inReview' => $this->vehicles()->where('status', 'in_review')->count(),
            'changesWaiting' => $this->vehicles()->whereNotNull('pending_changes')->count(),
            'symbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
        ];
        if ($this->selectedId) {
            $data['vehicle'] = $this->vehicles()->with(['provider.user', 'franchise', 'zone'])->findOrFail($this->selectedId);
        } else {
            $data['vehicles'] = $this->vehicles()->with(['provider.user', 'franchise', 'zone'])
                ->when($this->statusFilter === 'changes', fn ($q) => $q->whereNotNull('pending_changes'))
                ->when(array_key_exists($this->statusFilter, VehicleListing::STATUS_LABELS), fn ($q) => $q->where('status', $this->statusFilter))
                ->orderByRaw("status = 'in_review' desc")->latest('id')->paginate(20);
        }

        return view('livewire.vehicle-listings.manage', $data)->layout('layouts.admin', ['title' => 'Vehicles for rent']);
    }
}
