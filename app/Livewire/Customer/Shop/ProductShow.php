<?php

namespace App\Livewire\Customer\Shop;

use App\Livewire\Customer\Shop\Concerns\AddsToShopCart;
use App\Livewire\Customer\Shop\Concerns\InteractsWithShopCity;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use Livewire\Component;

/** E-commerce Phase 2 — /{city}/shop/{shop}/{product}: photos, price, stock, the shop it comes from, add to bag. */
class ProductShow extends Component
{
    use AddsToShopCart;
    use InteractsWithShopCity;

    public Store $store;

    public Product $product;

    public function mount(string $city, string $storeSlug, string $productSlug): void
    {
        if (! $this->enterShopCity($city)) {
            return;
        }
        $this->store = $this->cityStores()->where('slug', $storeSlug)->firstOrFail();
        $this->product = Product::where('store_id', $this->store->id)->where('slug', $productSlug)
            ->where('is_active', true)->where('is_approved', true)->firstOrFail();
    }

    public function render()
    {
        $this->product->setRelation('store', $this->store);
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return view('livewire.customer.shop.product-show', [
            'qty' => $this->cartQuantities()[$this->product->id] ?? 0,
            'more' => Product::where('store_id', $this->store->id)->whereKeyNot($this->product->id)
                ->where('is_active', true)->where('is_approved', true)->where('stock', '>', 0)->inRandomOrder()->limit(5)->get()
                ->each->setRelation('store', $this->store),
            'inCart' => $this->cartQuantities(),
            'city' => $this->shopCity,
            'currencySymbol' => $symbol,
        ])->layout('components.layouts.customer', [
            'title' => $this->product->name.' — '.$this->store->name,
            'metaDescription' => \Illuminate\Support\Str::limit($this->product->description ?: $this->product->name.' from '.$this->store->name.', '.$this->shopCity->name.'.', 155),
            'ogImage' => $this->product->image_urls[0] ?? null,
            'indexable' => true,
            'schema' => [[
                '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $this->product->name,
                'image' => $this->product->image_urls, 'description' => (string) $this->product->description,
                'offers' => ['@type' => 'Offer', 'priceCurrency' => 'INR', 'price' => number_format($this->product->effective_price, 2, '.', ''),
                    'availability' => $this->product->stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'seller' => ['@type' => 'Organization', 'name' => $this->store->name]],
            ]],
        ]);
    }
}
