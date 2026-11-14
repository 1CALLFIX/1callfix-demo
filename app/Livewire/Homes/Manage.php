<?php

namespace App\Livewire\Homes;

use App\Livewire\Venue\HomeEditor;
use App\Models\HouseListing;
use App\Models\HouseVisit;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Support\HomeSettings;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Rental → Property for rent (owner 2026-11-14). The team approves every home before customers see it, and every
 * change to a live home's photos, description, landmark or address. Approve / Send back (with a reason the owner sees)
 * / Feature / Switch off. A "Visits" tab shows visit requests (read only). Row scope: `homes.manage` on the home's own
 * zone / franchise / city / country.
 */
class Manage extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'in_review';

    public ?int $selectedId = null;

    public string $reason = '';

    private const COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('homes.manage'), 403, 'You do not have permission to manage property for rent.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->reset('selectedId', 'reason');
    }

    private function homes()
    {
        return app(AuthorizationService::class)->scopeQuery(HouseListing::query(), auth()->user(), 'homes.manage', self::COLUMNS);
    }

    private function find(int $id): HouseListing
    {
        return $this->homes()->with('franchise')->find($id) ?? abort(404);
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
        $home = $this->find($id);
        if ($home->status !== 'in_review') {
            session()->flash('error', 'Only a home waiting for review can be approved.');

            return;
        }
        $problems = HomeEditor::submitProblems($home);
        if ($problems !== []) {
            session()->flash('error', 'Not ready to approve: '.implode(' ', $problems));

            return;
        }
        $home->update(['status' => 'approved', 'approved_at' => now(), 'rejection_reason' => null, 'is_active' => true, 'rented_at' => null,
            'expires_at' => now()->addDays((int) HomeSettings::get('homes.listing_days', $home->scope()))]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home for rent approved (admin)');
        session()->flash('message', "{$home->title} approved.");
    }

    public function sendBack(int $id): void
    {
        $home = $this->find($id);
        if (! in_array($home->status, ['in_review', 'approved'], true)) {
            session()->flash('error', 'This home is not waiting for review.');

            return;
        }
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Tell the owner what to change.']);
        $home->update(['status' => 'rejected', 'rejection_reason' => trim($this->reason), 'approved_at' => null, 'is_active' => false]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home for rent sent back for changes (admin)', ['reason' => trim($this->reason)]);
        $this->reset('reason');
        session()->flash('message', "{$home->title} sent back to the owner with your reason.");
    }

    public function approveChanges(int $id): void
    {
        $home = $this->find($id);
        $changes = (array) $home->pending_changes;
        if ($changes === []) {
            return;
        }
        $home->update(array_intersect_key($changes, array_flip(HouseListing::REVIEWED)) + ['pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => null]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home change approved (admin)', ['fields' => array_keys($changes)]);
        session()->flash('message', "Change to {$home->title} approved. Customers see it now.");
    }

    public function rejectChanges(int $id): void
    {
        $home = $this->find($id);
        if ($home->pending_changes === null) {
            return;
        }
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Tell the owner why.']);
        $home->update(['pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => trim($this->reason)]);
        ActivityLogger::logModel(auth()->user(), $home, 'Home change not accepted (admin)', ['reason' => trim($this->reason)]);
        $this->reset('reason');
        session()->flash('message', "Change to {$home->title} not accepted. The owner sees your reason.");
    }

    public function toggleFeatured(int $id): void
    {
        $home = $this->find($id);
        $home->update(['is_featured' => ! $home->is_featured]);
        ActivityLogger::logModel(auth()->user(), $home, $home->is_featured ? 'Home featured (admin)' : 'Home no longer featured (admin)');
    }

    public function toggleActive(int $id): void
    {
        $home = $this->find($id);
        if ($home->status !== 'approved') {
            return;
        }
        $home->update(['is_active' => ! $home->is_active]);
        ActivityLogger::logModel(auth()->user(), $home, $home->is_active ? 'Home switched on (admin)' : 'Home switched off (admin)');
    }

    public function render()
    {
        $data = [
            'inReview' => $this->homes()->where('status', 'in_review')->count(),
            'changesWaiting' => $this->homes()->whereNotNull('pending_changes')->count(),
            'symbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
        ];
        if ($this->selectedId) {
            $data['home'] = $this->homes()->with(['provider.user', 'franchise', 'zone'])->findOrFail($this->selectedId);
            $data['unit'] = HomeSettings::areaUnit($data['home']->scope());
        } elseif ($this->statusFilter === 'visits') {
            $data['visits'] = HouseVisit::with(['listing', 'customer'])->whereIn('house_listing_id', $this->homes()->select('id'))->latest('id')->paginate(30);
        } else {
            $data['homes'] = $this->homes()->with(['provider.user', 'franchise', 'zone'])
                ->when($this->statusFilter === 'changes', fn ($q) => $q->whereNotNull('pending_changes'))
                ->when(array_key_exists($this->statusFilter, HouseListing::STATUS_LABELS), fn ($q) => $q->where('status', $this->statusFilter))
                ->orderByRaw("status = 'in_review' desc")->latest('id')->paginate(20);
        }

        return view('livewire.homes.manage', $data)->layout('layouts.admin', ['title' => 'Property for rent']);
    }
}
