<?php

namespace App\Livewire\Products;

use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 24 (Marketplace Foundation) admin screen. `Product` has no
 * franchise/zone column of its own -- scoped through its owning `store`
 * relation, same dotted-relation convention `AuthorizationService::
 * scopeQuery()` already supports for every other cross-relation scope hint
 * in this codebase.
 */
class Manage extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $storeIdFilter = null;

    public ?int $storeId = null;
    public ?int $marketplaceCategoryId = null;
    public string $name = '';
    public string $price = '0';
    public string $stock = '0';

    public bool $showEditModal = false;
    public ?int $editProductId = null;
    public string $editName = '';
    public string $editPrice = '0';
    public string $editStock = '0';
    public bool $editIsActive = true;
    public bool $editIsApproved = true;

    public string $newVariantName = '';
    public string $newVariantPriceOverride = '';
    public string $newVariantStock = '0';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('products.manage'), 403, 'You do not have permission to manage products.');
    }

    private function scopeColumns(): array
    {
        return [
            'zone_id' => 'store.zone_id', 'franchise_id' => 'store.franchise_id',
            'city_id' => 'store.franchise.city_id', 'country_id' => 'store.franchise.country_id',
        ];
    }

    private function scopedProductsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Product::query(), auth()->user(), 'products.manage', $this->scopeColumns())
            ->with(['store', 'category']);
    }

    public function createProduct(): void
    {
        $this->validate([
            'storeId' => 'required|exists:stores,id',
            'marketplaceCategoryId' => 'nullable|exists:marketplace_categories,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ]);

        Product::create([
            'store_id' => $this->storeId, 'marketplace_category_id' => $this->marketplaceCategoryId,
            'name' => $this->name, 'price' => $this->price, 'stock' => $this->stock,
            'is_active' => true, 'is_approved' => true,
        ]);

        $this->reset(['storeId', 'marketplaceCategoryId', 'name', 'price', 'stock']);
        session()->flash('message', 'Product created.');
    }

    public function editProduct(int $productId): void
    {
        $product = $this->scopedProductsQuery()->with('variants')->find($productId);
        abort_if(! $product, 404);

        $this->editProductId = $product->id;
        $this->editName = $product->name;
        $this->editPrice = (string) $product->price;
        $this->editStock = (string) $product->stock;
        $this->editIsActive = $product->is_active;
        $this->editIsApproved = $product->is_approved;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $product = $this->scopedProductsQuery()->find($this->editProductId);
        abort_if(! $product, 404);

        $this->validate([
            'editName' => 'required|string|max:255',
            'editPrice' => 'required|numeric|min:0',
            'editStock' => 'required|integer|min:0',
        ]);

        $product->update([
            'name' => $this->editName, 'price' => $this->editPrice, 'stock' => $this->editStock,
            'is_active' => $this->editIsActive, 'is_approved' => $this->editIsApproved,
        ]);

        session()->flash('message', 'Product updated.');
    }

    public function addVariant(): void
    {
        $product = $this->scopedProductsQuery()->find($this->editProductId);
        abort_if(! $product, 404);

        $this->validate([
            'newVariantName' => 'required|string|max:255',
            'newVariantPriceOverride' => 'nullable|numeric|min:0',
            'newVariantStock' => 'required|integer|min:0',
        ]);

        ProductVariant::create([
            'product_id' => $product->id, 'name' => $this->newVariantName,
            'price_override' => $this->newVariantPriceOverride !== '' ? $this->newVariantPriceOverride : null,
            'stock' => $this->newVariantStock, 'is_active' => true,
        ]);

        $this->reset(['newVariantName', 'newVariantPriceOverride', 'newVariantStock']);
        session()->flash('message', 'Variant added.');
    }

    public function getEditingProductProperty(): ?Product
    {
        if (! $this->editProductId) {
            return null;
        }

        return $this->scopedProductsQuery()->with('variants')->find($this->editProductId);
    }

    public function render()
    {
        $products = $this->scopedProductsQuery()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->storeIdFilter, fn ($q) => $q->where('store_id', $this->storeIdFilter))
            ->latest('id')
            ->paginate(20);

        return view('livewire.products.manage', [
            'products' => $products,
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
            'categories' => MarketplaceCategory::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Products']);
    }
}
