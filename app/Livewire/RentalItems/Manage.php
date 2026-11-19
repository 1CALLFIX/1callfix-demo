<?php

namespace App\Livewire\RentalItems;

use App\Models\RentalItem;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\RentalItems\RentalItemService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Rental → People's posts (Rent anything, owner decision 2026-11-18, OLX-style; Second-hand added 2026-11-19,
 * one queue with a For rent / Second-hand tab, same permission and franchise scope). The franchise team approves every posted item
 * before customers see it, and handles reports: "Item is fine" puts a hidden item back up; "Agree, send back" returns
 * it to the owner with the reason. Approve / Send back / Feature / Switch off. Row scope: `rental_items.manage` on the
 * item's own zone / franchise / city / country, so a franchise team sees only its own zone's items.
 */
class Manage extends Component
{
    use WithPagination;

    public const TABS = ['in_review' => 'Waiting for review', 'reported' => 'Reported', 'approved' => 'Approved', 'rejected' => 'Needs changes', '' => 'All'];

    /** Second-hand (2026-11-19): which kind of post. */
    public const TYPES = ['' => 'All posts', 'rent' => 'For rent', 'sell' => 'Second-hand'];

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'in_review';

    public ?int $selectedId = null;

    public string $reason = '';

    private const PERMISSION = 'rental_items.manage';

    private const COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere(self::PERMISSION), 403, 'You do not have permission to manage posted items (rent and second-hand).');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->reset('selectedId', 'reason');
    }

    public function updatingTypeFilter(): void
    {
        $this->updatingStatusFilter();
    }

    private function items()
    {
        return app(AuthorizationService::class)->scopeQuery(RentalItem::query(), auth()->user(), self::PERMISSION, self::COLUMNS)
            ->when(in_array($this->typeFilter, \App\Support\PostTypes::ALL, true), fn ($q) => $q->where('deal', $this->typeFilter));
    }

    private function find(int $id): RentalItem
    {
        return $this->items()->with('franchise')->find($id) ?? abort(404);
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

    private function run(int $id, string $log, callable $action, string $done): void
    {
        $item = $this->find($id);
        try {
            $action($item);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        ActivityLogger::logModel(auth()->user(), $item, $log, $this->reason !== '' ? ['reason' => trim($this->reason)] : []);
        $this->reset('reason');
        session()->flash('message', $done);
    }

    private function needReason(string $message): bool
    {
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => $message, 'reason.min' => $message]);

        return true;
    }

    public function approve(int $id): void
    {
        $this->run($id, 'Posted item approved (admin)', fn (RentalItem $i) => app(RentalItemService::class)->approve(auth()->user(), $i), 'Approved. Customers see it now.');
    }

    public function sendBack(int $id): void
    {
        $this->needReason('Tell the owner what to change.');
        $this->run($id, 'Posted item sent back (admin)', fn (RentalItem $i) => app(RentalItemService::class)->sendBack(auth()->user(), $i, $this->reason), 'Sent back to the owner with your reason.');
    }

    public function dismissReports(int $id): void
    {
        $this->run($id, 'Posted item reports dismissed (admin)', fn (RentalItem $i) => app(RentalItemService::class)->dismissReports(auth()->user(), $i), 'Reports closed. The item is up again.');
    }

    public function upholdReports(int $id): void
    {
        $this->needReason('Tell the owner why it was taken down.');
        $this->run($id, 'Posted item reports upheld (admin)', fn (RentalItem $i) => app(RentalItemService::class)->upholdReports(auth()->user(), $i, $this->reason), 'Taken down and sent back to the owner with your reason.');
    }

    public function toggleActive(int $id): void
    {
        $item = $this->find($id);
        if ($item->status !== 'approved' || $item->isSold()) {
            return;
        }
        $item->update(['is_active' => ! $item->is_active]);
        ActivityLogger::logModel(auth()->user(), $item, $item->is_active ? 'Posted item switched on (admin)' : 'Posted item switched off (admin)');
    }

    public function toggleFeatured(int $id): void
    {
        $item = $this->find($id);
        $item->update(['is_featured' => ! $item->is_featured]);
        ActivityLogger::logModel(auth()->user(), $item, $item->is_featured ? 'Posted item featured (admin)' : 'Posted item no longer featured (admin)');
    }

    public function render()
    {
        $data = [
            'inReview' => $this->items()->where('status', 'in_review')->count(),
            'reported' => $this->items()->where('open_reports', '>', 0)->count(),
        ];
        if ($this->selectedId) {
            $data['item'] = $this->items()->with(['user', 'franchise', 'zone', 'reports.user'])->findOrFail($this->selectedId);
        } else {
            $data['items'] = $this->items()->with(['user', 'franchise', 'zone'])
                ->when($this->statusFilter === 'reported', fn ($q) => $q->where('open_reports', '>', 0)->orderByRaw('hidden_for_reports_at is null')->orderByDesc('open_reports'))
                ->when(array_key_exists($this->statusFilter, RentalItem::STATUS_LABELS), fn ($q) => $q->where('status', $this->statusFilter))
                ->orderByRaw("status = 'in_review' desc")->oldest('submitted_at')->latest('id')->paginate(20);
        }

        return view('livewire.rental-items.manage', $data)->layout('layouts.admin', ['title' => "People's posts"]);
    }
}
