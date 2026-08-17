<?php

namespace App\Livewire\AddOns;

use App\Models\AddOn;
use App\Models\Store;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/** Phase 24 (Marketplace Foundation) admin screen -- reuses `products.manage` (no separate permission; add-ons are a sub-resource of a store's catalog, same reasoning variants don't get their own permission either). */
class Manage extends Component
{
    use WithPagination;

    public ?int $storeIdFilter = null;

    public ?int $storeId = null;
    public string $name = '';
    public string $price = '0';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('products.manage'), 403, 'You do not have permission to manage add-ons.');
    }

    private function scopeColumns(): array
    {
        return [
            'zone_id' => 'store.zone_id', 'franchise_id' => 'store.franchise_id',
            'city_id' => 'store.franchise.city_id', 'country_id' => 'store.franchise.country_id',
        ];
    }

    private function scopedAddOnsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(AddOn::query(), auth()->user(), 'products.manage', $this->scopeColumns())
            ->with('store');
    }

    public function createAddOn(): void
    {
        $this->validate([
            'storeId' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        AddOn::create(['store_id' => $this->storeId, 'name' => $this->name, 'price' => $this->price, 'is_active' => true]);

        $this->reset(['storeId', 'name', 'price']);
        session()->flash('message', 'Add-on created.');
    }

    public function toggleActive(int $addOnId): void
    {
        $addOn = $this->scopedAddOnsQuery()->find($addOnId);
        abort_if(! $addOn, 404);

        $addOn->update(['is_active' => ! $addOn->is_active]);
    }

    public function render()
    {
        $addOns = $this->scopedAddOnsQuery()
            ->when($this->storeIdFilter, fn ($q) => $q->where('store_id', $this->storeIdFilter))
            ->latest('id')
            ->paginate(20);

        return view('livewire.add-ons.manage', [
            'addOns' => $addOns,
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Add-Ons']);
    }
}
