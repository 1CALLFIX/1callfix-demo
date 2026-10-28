<?php

namespace App\Livewire\Customer\Shop;

use App\Livewire\Customer\Shop\Concerns\AddsToShopCart;
use App\Livewire\Customer\Shop\Concerns\InteractsWithShopCity;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Store;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** E-commerce Phase 2 — /{city}/shop/{shop}: one shop, its terms up front (minimum order, delivery fee), its items. */
class StoreShow extends Component
{
    use AddsToShopCart;
    use InteractsWithShopCity;
    use WithPagination;

    public Store $store;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public ?int $categoryId = null;

    public function mount(string $city, string $storeSlug): void
    {
        if (! $this->enterShopCity($city)) {
            return;
        }
        $this->store = $this->cityStores()->where('slug', $storeSlug)->firstOrFail();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $base = Product::where('store_id', $this->store->id)->where('is_active', true)->where('is_approved', true);
        $term = trim($this->search);

        return view('livewire.customer.shop.store-show', [
            'products' => (clone $base)
                ->when($this->categoryId, fn ($q) => $q->where('marketplace_category_id', $this->categoryId))
                ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
                ->with('store:id,name,slug,is_open')->orderByRaw('stock > 0 desc')->orderBy('name')->paginate(30),
            'categories' => MarketplaceCategory::whereIn('id', (clone $base)->whereNotNull('marketplace_category_id')->distinct()->pluck('marketplace_category_id'))
                ->orderBy('position')->orderBy('name')->get(['id', 'name']),
            'rating' => Review::whereHas('marketplaceOrder', fn ($q) => $q->where('store_id', $this->store->id))->selectRaw('avg(rating) as avg, count(*) as n')->first(),
            'inCart' => $this->cartQuantities(),
            'city' => $this->shopCity,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', [
            'title' => $this->store->name.' — shop in '.$this->shopCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit($this->store->description ?: 'Order from '.$this->store->name.' in '.$this->shopCity->name.' on '.Setting::get('branding.platform_name', '1CallFix').'.', 155),
            'ogImage' => $this->store->logo_url,
            'indexable' => true,
        ]);
    }
}
