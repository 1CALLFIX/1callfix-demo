<?php

namespace App\Livewire\Equipment;

use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\Provider;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * RENTAL MODULE IMPLEMENTATION admin screen -- the catalog-management
 * counterpart to Vehicles\Manage/Properties\Manage, for Equipment/Machinery
 * inventory.
 */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $providerId = null;
    public ?int $equipmentCategoryId = null;
    public ?int $zoneId = null;
    public string $name = '';
    public string $manufacturer = '';
    public string $basePrice = '0';
    public string $pricingUnit = 'daily';
    public string $depositAmount = '';

    public bool $showEditModal = false;
    public ?int $editItemId = null;
    public string $editName = '';
    public bool $editIsActive = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('equipment.manage'), 403, 'You do not have permission to manage equipment.');
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedItemsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(EquipmentItem::query(), auth()->user(), 'equipment.manage', $this->scopeColumns())
            ->with(['provider.user', 'equipmentCategory', 'franchise']);
    }

    public function createItem(): void
    {
        $this->validate([
            'providerId' => 'required|exists:providers,id',
            'equipmentCategoryId' => 'required|exists:equipment_categories,id',
            'zoneId' => 'required|exists:zones,id',
            'name' => 'required|string|max:255',
            'basePrice' => 'required|numeric|min:0',
            'pricingUnit' => 'required|in:hourly,daily,weekly,monthly',
        ]);

        $zone = Zone::with('franchise')->findOrFail($this->zoneId);

        // Same gap Vehicles\Manage had -- the zones dropdown is unscoped, so
        // a zone-scoped actor holding equipment.manage only in Zone A could
        // otherwise create an item under any other zone/franchise
        // platform-wide. edit/saveEdit were already safe (scopedItemsQuery()
        // 404s out-of-scope); create had no check at all.
        if (! auth()->user()->hasPermission('equipment.manage', [
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])) {
            $this->addError('zoneId', 'You do not have permission to create equipment in this zone.');
            return;
        }

        EquipmentItem::create([
            'provider_id' => $this->providerId, 'equipment_category_id' => $this->equipmentCategoryId,
            'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
            'name' => $this->name, 'manufacturer' => $this->manufacturer !== '' ? $this->manufacturer : null,
            'base_price' => $this->basePrice, 'pricing_unit' => $this->pricingUnit,
            'deposit_amount' => $this->depositAmount !== '' ? $this->depositAmount : null,
            'is_active' => true,
        ]);

        $this->reset(['providerId', 'equipmentCategoryId', 'zoneId', 'name', 'manufacturer', 'basePrice', 'depositAmount']);
        $this->pricingUnit = 'daily';
        session()->flash('message', 'Equipment item created.');
    }

    public function editItem(int $itemId): void
    {
        $item = $this->scopedItemsQuery()->find($itemId);
        abort_if(! $item, 404);

        $this->editItemId = $item->id;
        $this->editName = $item->name;
        $this->editIsActive = $item->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $item = $this->scopedItemsQuery()->find($this->editItemId);
        abort_if(! $item, 404);

        $this->validate(['editName' => 'required|string|max:255']);

        $item->update(['name' => $this->editName, 'is_active' => $this->editIsActive]);

        $this->showEditModal = false;
        session()->flash('message', 'Equipment item updated.');
    }

    public function render()
    {
        $items = $this->scopedItemsQuery()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->latest('id')
            ->paginate(20);

        return view('livewire.equipment.manage', [
            'items' => $items,
            'providers' => Provider::with('user')->get(),
            'equipmentCategories' => EquipmentCategory::where('is_active', true)->orderBy('name')->get(),
            'zones' => Zone::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Equipment']);
    }
}
