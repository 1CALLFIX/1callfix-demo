<?php

namespace App\Livewire\Stores;

use App\Livewire\Concerns\HasRowArchive;
use Illuminate\Database\Eloquent\Model;
use App\Livewire\Concerns\ScopesOwnerProviders;
use App\Models\Store;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Support\Modules;
use Livewire\Component;
use Livewire\WithPagination;

/** Phase 24 (Marketplace Foundation) admin screen — the catalog-management counterpart to Properties\Manage. Row-scoped the same way. */
class Manage extends Component
{
    use ScopesOwnerProviders;
    use WithPagination;
    use HasRowArchive;

    public string $search = '';
    public string $moduleFilter = '';

    /** E-commerce Phase 1: '' | pending | approved | rejected (shops sellers opened through /partners). */
    public string $approvalFilter = '';

    public ?int $rejectStoreId = null;
    public string $rejectNote = '';

    public ?int $providerId = null;
    public ?int $zoneId = null;
    public string $module = 'commerce';
    public string $name = '';
    public string $addressLine = '';
    public ?float $lat = null;
    public ?float $lng = null;
    public string $phone = '';

    public bool $showEditModal = false;
    public ?int $editStoreId = null;
    public string $editName = '';
    public bool $editIsActive = true;
    public bool $editIsOpen = true;
    public bool $editPrescriptionRequired = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('stores.manage'), 403, 'You do not have permission to manage stores.');
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    protected function archiveModel(): string
    {
        return \App\Models\Store::class;
    }

    /** stores.manage within the admin's own scope (a crafted id outside it is refused). */
    protected function canArchiveRow(Model $row): bool
    {
        if (! auth()->user()->hasPermissionAnywhere('stores.manage')) {
            return false;
        }

        $q = $this->scopedStoresQuery();

        return ($row->trashed() ? $q->onlyTrashed() : $q)->whereKey($row->getKey())->exists();
    }

    protected function archiveLabel(Model $row): string
    {
        return $row->name ?: '#'.$row->getKey();
    }

    private function scopedStoresQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Store::query(), auth()->user(), 'stores.manage', $this->scopeColumns())
            ->with(['provider.user', 'franchise']);
    }

    public function createStore(): void
    {
        $this->validate([
            'providerId' => 'required|exists:providers,id',
            'zoneId' => 'required|exists:zones,id',
            'module' => 'required|in:'.implode(',', array_keys(Modules::ALL)),
            'name' => 'required|string|max:255',
            'addressLine' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $zone = Zone::with('franchise')->findOrFail($this->zoneId);

        // Same gap as the other catalog Manage screens -- the zones dropdown
        // is unscoped, so a zone-scoped actor holding stores.manage only in
        // Zone A could otherwise create a store under any other
        // zone/franchise platform-wide. edit/saveEdit were already safe
        // (scopedStoresQuery() 404s out-of-scope); create had no check.
        if (! auth()->user()->hasPermission('stores.manage', [
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])) {
            $this->addError('zoneId', 'You do not have permission to create a store in this zone.');
            return;
        }

        if (! $this->ownerProviderAllowed('stores.manage')) {
            return;
        }

        Store::create([
            'provider_id' => $this->providerId, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
            'module' => $this->module, 'name' => $this->name, 'address_line' => $this->addressLine,
            'lat' => $this->lat, 'lng' => $this->lng, 'phone' => $this->phone ?: null,
            'is_active' => true, 'is_open' => true,
        ]);

        $this->reset(['providerId', 'zoneId', 'name', 'addressLine', 'lat', 'lng', 'phone']);
        session()->flash('message', 'Store created.');
    }

    public function editStore(int $storeId): void
    {
        $store = $this->scopedStoresQuery()->find($storeId);
        abort_if(! $store, 404);

        $this->editStoreId = $store->id;
        $this->editName = $store->name;
        $this->editIsActive = $store->is_active;
        $this->editIsOpen = $store->is_open;
        $this->editPrescriptionRequired = $store->prescription_required;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $store = $this->scopedStoresQuery()->find($this->editStoreId);
        abort_if(! $store, 404);

        $this->validate(['editName' => 'required|string|max:255']);

        $store->update([
            'name' => $this->editName, 'is_active' => $this->editIsActive,
            'is_open' => $this->editIsOpen, 'prescription_required' => $this->editPrescriptionRequired,
        ]);

        $this->showEditModal = false;
        session()->flash('message', 'Store updated.');
    }

    /**
     * E-commerce Phase 1: approve a shop a seller opened through /partners. Only once the seller's documents are
     * approved (the provider KYC queue); approving switches the shop on, and the seller opens it from their portal.
     */
    public function approveStore(int $storeId): void
    {
        $store = $this->scopedStoresQuery()->find($storeId);
        abort_if(! $store, 404);

        if ($store->provider?->kyc_status !== 'approved') {
            session()->flash('error', "Approve {$store->provider?->user?->name}'s documents first (Providers → KYC), then the shop.");

            return;
        }

        $store->update(['approval_status' => 'approved', 'approval_note' => null, 'approved_at' => now(), 'approved_by' => auth()->id(), 'is_active' => true]);
        ActivityLogger::logModel(auth()->user(), $store, 'Shop approved');
        session()->flash('message', "{$store->name} approved. The seller can open it now.");
    }

    public function startRejectStore(int $storeId): void
    {
        abort_if(! $this->scopedStoresQuery()->find($storeId), 404);
        $this->rejectStoreId = $storeId;
        $this->rejectNote = '';
    }

    public function rejectStore(): void
    {
        $store = $this->scopedStoresQuery()->find($this->rejectStoreId);
        abort_if(! $store, 404);
        $this->validate(['rejectNote' => 'required|string|min:5|max:500'], ['rejectNote.required' => 'Tell the seller what to fix.']);

        $store->update(['approval_status' => 'rejected', 'approval_note' => trim($this->rejectNote), 'is_active' => false, 'is_open' => false]);
        ActivityLogger::logModel(auth()->user(), $store, 'Shop not approved', ['note' => trim($this->rejectNote)]);
        $this->rejectStoreId = null;
        session()->flash('message', "{$store->name} not approved. The seller sees your note.");
    }

    public function render()
    {
        $stores = $this->applyActiveFilter($this->scopedStoresQuery())
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->moduleFilter !== '', fn ($q) => $q->where('module', $this->moduleFilter))
            ->when($this->approvalFilter !== '', fn ($q) => $q->where('approval_status', $this->approvalFilter))
            ->latest('id')
            ->paginate(20);

        return view('livewire.stores.manage', [
            'canManage' => auth()->user()->hasPermissionAnywhere('stores.manage'),
            'canForce' => $this->isSuperAdminUser(),
            'archiveBars' => $this->archiveBars(),
            'stores' => $stores,
            'providers' => $this->ownerProviderOptions('stores.manage'),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(),
            'modules' => Modules::ALL,
            'pendingApprovals' => $this->scopedStoresQuery()->where('approval_status', 'pending')->count(),
        ])->layout('layouts.admin', ['title' => 'Stores']);
    }
}
