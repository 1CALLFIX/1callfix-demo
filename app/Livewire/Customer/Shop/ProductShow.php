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

        // A choice with only one value on sale is picked for the customer.
        foreach ($this->choiceValues() as $name => $values) {
            if (count($values) === 1) {
                $this->picked[$name] = $values[0];
            }
        }
    }

    public function pick(string $name, string $value): void
    {
        if (! in_array($value, $this->choiceValues()[$name] ?? [], true)) {
            return;
        }
        $this->picked[$name] = $value;
        $this->cartMessage = '';
        if ($this->missingChoice === $name) {
            $this->missingChoice = '';
        }
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

    private function choiceQuantity(\App\Models\ProductVariant $variant): int
    {
        return auth()->check()
            ? (int) \App\Models\CartItem::where('user_id', auth()->id())->where('product_variant_id', $variant->id)->value('quantity')
            : app(\App\Services\GuestShopCart::class)->quantityFor($this->product->id, $variant->id);
    }

    /** Adds one of the picked choice (size, colour…) to the bag, within its stock and the product's limit. */
    public function addChoice(): void
    {
        $this->changeChoice(+1);
    }

    public function removeChoiceOne(): void
    {
        $this->changeChoice(-1);
    }

    private function changeChoice(int $step): void
    {
        $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
        $variant = $this->selectedVariant();
        if (! $variant) {
            $missing = collect($this->product->option_names ?? [])->first(fn ($n) => ! isset($this->picked[$n]));
            if ($missing !== null) {
                // Shown in red on the choice itself (Meesho-style), not as a separate message.
                $this->missingChoice = (string) $missing;
            } else {
                $this->cartMessage = $t('shop.product.choice_sold_out', ['choice' => implode(' / ', $this->picked)]);
            }

            return;
        }
        $stillSold = Product::whereKey($this->product->id)->where('is_active', true)->where('is_approved', true)
            ->whereHas('store', fn ($q) => $q->publiclyVisible())->exists();
        if (! $stillSold) {
            $this->cartMessage = $t('shop.item_gone');

            return;
        }

        $guest = ! auth()->check();
        $line = $guest ? null : \App\Models\CartItem::where('user_id', auth()->id())->where('product_id', $this->product->id)
            ->where('product_variant_id', $variant->id)->first();
        $wanted = $this->choiceQuantity($variant) + $step;
        $limit = min((int) $variant->stock, (int) ($this->product->maximum_cart_quantity ?: PHP_INT_MAX));
        if ($step > 0 && $wanted > $limit) {
            $this->cartMessage = $limit <= 0 ? $t('shop.product.choice_sold_out', ['choice' => $variant->name])
                : "Only {$limit} of {$this->product->name} ({$variant->name}) can be ordered.";

            return;
        }

        if ($guest) {
            app(\App\Services\GuestShopCart::class)->set($this->product->id, $variant->id, max(0, $wanted));
            $this->cartMessage = '';
            $this->dispatch('shop-cart-changed');

            return;
        }

        $cart = app(\App\Services\CartService::class);
        if ($line === null) {
            $cart->add(auth()->user(), $this->product, 1, $variant);
        } elseif ($wanted <= 0) {
            $cart->remove($line);
        } else {
            $cart->updateQuantity($line, $wanted);
        }
        $this->cartMessage = '';
        $this->dispatch('shop-cart-changed');
    }

    /** The active choices of this product, each knowing its product. */
    private function variants()
    {
        $this->product->loadMissing('activeVariants');

        return $this->product->activeVariants->each->setRelation('product', $this->product);
    }

    /** choice name => values on sale, in the seller's order. */
    private function choiceValues(): array
    {
        if (! $this->product->hasChoices()) {
            return [];
        }
        $out = [];
        foreach ($this->product->option_names as $name) {
            $out[$name] = $this->variants()->map(fn ($v) => $v->options[$name] ?? null)->filter()->unique()->values()->all();
        }

        return $out;
    }

    private function selectedVariant(): ?\App\Models\ProductVariant
    {
        $names = $this->product->option_names ?? [];
        if (! $this->product->hasChoices() || count(array_intersect_key($this->picked, array_flip($names))) < count($names)) {
            return null;
        }

        return $this->variants()->first(function ($v) use ($names) {
            foreach ($names as $n) {
                if (($v->options[$n] ?? null) !== $this->picked[$n]) {
                    return false;
                }
            }

            return true;
        });
    }

    /** Stock of the one choice this value makes with the other picks, or null when it is not one choice yet. */
    private function stockFor(string $name, string $value): ?int
    {
        $names = $this->product->option_names ?? [];
        $others = array_diff($names, [$name]);
        if (count(array_intersect_key($this->picked, array_flip($others))) < count($others)) {
            return null;
        }
        $match = $this->variants()->first(function ($v) use ($names, $name, $value) {
            foreach ($names as $n) {
                if (($v->options[$n] ?? null) !== ($n === $name ? $value : $this->picked[$n])) {
                    return false;
                }
            }

            return true;
        });

        return $match ? (int) $match->stock : null;
    }

    /** Can this value still be bought with the other picks? (in stock, on sale) */
    private function valueInStock(string $name, string $value): bool
    {
        return $this->variants()->contains(function ($v) use ($name, $value) {
            if (($v->options[$name] ?? null) !== $value || $v->stock <= 0) {
                return false;
            }
            foreach ($this->picked as $n => $picked) {
                if ($n !== $name && ($v->options[$n] ?? null) !== $picked) {
                    return false;
                }
            }

            return true;
        });
    }

    public function render()
    {
        $this->product->setRelation('store', $this->store);
        $symbol = Setting::get('locale.currency_symbol', '₹');

        $choices = [];
        foreach ($this->choiceValues() as $name => $values) {
            $choices[$name] = collect($values)->mapWithKeys(fn ($v) => [$v => $this->valueInStock($name, $v)])->all();
        }
        $selected = $this->selectedVariant();
        $choiceQty = $selected ? $this->choiceQuantity($selected) : 0;
        // Low stock under each value, when one pick decides it (Flipkart: "Only 2 left").
        $lowStock = [];
        foreach ($this->choiceValues() as $name => $values) {
            foreach ($values as $value) {
                $stock = $this->stockFor($name, $value);
                if ($stock !== null && $stock > 0 && $stock <= 5) {
                    $lowStock[$name][$value] = $stock;
                }
            }
        }

        return view('livewire.customer.shop.product-show', [
            'qty' => $this->cartQuantities()[$this->product->id] ?? 0,
            'choices' => $choices,
            'selected' => $selected,
            'choiceQty' => $choiceQty,
            'lowStock' => $lowStock,
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
