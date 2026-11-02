<?php

namespace App\Livewire\Customer\Shop;

use App\Livewire\Customer\Shop\Concerns\AddsToShopCart;
use App\Livewire\Customer\Shop\Concerns\InteractsWithShopCity;
use App\Livewire\Customer\Shop\Concerns\PicksProductChoices;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use Livewire\Component;

/** E-commerce Phase 2 — /{city}/shop/{shop}/{product}: photos, price, stock, the shop it comes from, add to bag. */
class ProductShow extends Component
{
    use AddsToShopCart;
    use PicksProductChoices;
    use InteractsWithShopCity;

    public Store $store;

    public Product $product;

    /** Product choices 2026-11-02: what the customer picked, choice name => value (e.g. Size => M). */
    public array $picked = [];

    /** Shop Phase A: the choice the customer still has to pick (shown in red, Meesho-style). */
    public string $missingChoice = '';

    public function mount(string $city, string $storeSlug, string $productSlug): void
    {
        if (! $this->enterShopCity($city)) {
            return;
        }
        $this->store = $this->cityStores()->where('slug', $storeSlug)->firstOrFail();
        $this->product = Product::where('store_id', $this->store->id)->where('slug', $productSlug)
            ->where('is_active', true)->where('is_approved', true)->firstOrFail();

        $this->pickSingleValues();
    }

    /**
     * Buy now (Amazon / Flipkart / Meesho): makes sure one of this product (or the picked choice) is in the cart, then
     * goes straight to this shop's checkout. A visitor signs in first and comes back to the checkout.
     */
    public function buyNow(): void
    {
        $this->cartMessage = '';
        if ($this->product->hasChoices()) {
            $variant = $this->selectedVariant();
            if (! $variant || $this->choiceQuantity($variant) === 0) {
                $this->changeChoice(+1);
            }
        } elseif ((auth()->check() ? ($this->cartQuantities()[$this->product->id] ?? 0) : app(\App\Services\GuestShopCart::class)->quantityFor($this->product->id)) === 0) {
            $this->addToCart($this->product->id);
        }
        if ($this->cartMessage !== '' || $this->missingChoice !== '') {
            return;
        }

        $checkout = route('customer.shop.checkout', $this->store->id);
        if (! auth()->check()) {
            session()->put('url.intended', $checkout);
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }
        $this->redirect($checkout, navigate: true);
    }

    public function render()
    {
        $this->product->setRelation('store', $this->store);
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return view('livewire.customer.shop.product-show', [
            'qty' => $this->cartQuantities()[$this->product->id] ?? 0,
            ...$this->choiceViewData(),
            'deliveryPromise' => \App\Support\MarketplaceSettings::deliveryPromise($this->store),
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
            // City URL architecture: never a product of a test or QA shop.
            'indexable' => ! $this->store->isTestShop() && ! \App\Support\Seo\QaRows::isQaName($this->store->name),
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
