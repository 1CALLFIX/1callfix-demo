<?php

namespace App\Livewire\Customer\Shop;

use App\Livewire\Customer\Shop\Concerns\AddsToShopCart;
use App\Livewire\Customer\Shop\Concerns\InteractsWithShopCity;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Modules;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * E-commerce Phase 2 — /{city}/shop: the city's own local shops. Search across every shop in the city, browse by
 * category, see which shops are open now. 1CallFix's own design (owner rule: not a copy of any marketplace): the
 * shop, not an anonymous warehouse, is front and centre, with what it charges for delivery said up front.
 */
class Home extends Component
{
    use AddsToShopCart;
    use InteractsWithShopCity;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public string $categorySlug = '';

    public function mount(string $city): void
    {
        $this->enterShopCity($city);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function pickCategory(string $slug): void
    {
        $this->categorySlug = $this->categorySlug === $slug ? '' : $slug;
        $this->resetPage();
    }

    public function render()
    {
        $storeIds = $this->cityStores()->pluck('id');
        $category = $this->categorySlug !== '' ? MarketplaceCategory::where('slug', $this->categorySlug)->where('is_active', true)->first() : null;
        $categoryIds = $category ? MarketplaceCategory::where('parent_id', $category->id)->pluck('id')->push($category->id) : null;
        $term = trim($this->search);

        $products = Product::whereIn('store_id', $storeIds)->where('is_active', true)->where('is_approved', true)
            ->when($categoryIds, fn ($q) => $q->whereIn('marketplace_category_id', $categoryIds))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")))
            ->with('store:id,name,slug,is_open')
            ->orderByRaw('stock > 0 desc')->orderBy('name')
            ->paginate(24);

        return view('livewire.customer.shop.home', [
            'stores' => $this->cityStores()->withCount(['products' => fn ($q) => $q->where('is_active', true)->where('is_approved', true)])
                ->orderByDesc('is_open')->orderBy('name')->get(),
            'categories' => MarketplaceCategory::where('module', Modules::COMMERCE)->whereNull('parent_id')->where('is_active', true)
                ->whereHas('products', fn ($q) => $q->whereIn('store_id', $storeIds)->where('is_active', true)->where('is_approved', true))
                ->orderBy('position')->orderBy('name')->get(['id', 'name', 'slug', 'image']),
            'products' => $products,
            'activeCategory' => $category,
            'inCart' => $this->cartQuantities(),
            'city' => $this->shopCity,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', [
            'title' => 'Shop local in '.$this->shopCity->name,
            'metaDescription' => 'Order from local shops in '.$this->shopCity->name.' on '.Setting::get('branding.platform_name', '1CallFix').' — delivered by our riders or ready for pickup.',
            'indexable' => $this->search === '' && $this->categorySlug === '',
        ]);
    }
}
