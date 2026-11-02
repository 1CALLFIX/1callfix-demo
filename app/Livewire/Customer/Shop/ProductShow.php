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
        if (! auth()->check()) {
            session()->put('url.intended', url()->previous());
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }
        $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
        $variant = $this->selectedVariant();
        if (! $variant) {
            $missing = collect($this->product->option_names ?? [])->first(fn ($n) => ! isset($this->picked[$n]));
            $this->cartMessage = $missing !== null
                ? $t('shop.product.pick_first', ['choice' => mb_strtolower((string) $missing)])
                : $t('shop.product.choice_sold_out', ['choice' => implode(' / ', $this->picked)]);

            return;
        }
        $stillSold = Product::whereKey($this->product->id)->where('is_active', true)->where('is_approved', true)
            ->whereHas('store', fn ($q) => $q->publiclyVisible())->exists();
        if (! $stillSold) {
            $this->cartMessage = $t('shop.item_gone');

            return;
        }

        $line = \App\Models\CartItem::where('user_id', auth()->id())->where('product_id', $this->product->id)
            ->where('product_variant_id', $variant->id)->first();
        $wanted = (int) ($line?->quantity ?? 0) + $step;
        $limit = min((int) $variant->stock, (int) ($this->product->maximum_cart_quantity ?: PHP_INT_MAX));
        if ($step > 0 && $wanted > $limit) {
            $this->cartMessage = $limit <= 0 ? $t('shop.product.choice_sold_out', ['choice' => $variant->name])
                : "Only {$limit} of {$this->product->name} ({$variant->name}) can be ordered.";

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
        $choiceQty = $selected && auth()->check()
            ? (int) \App\Models\CartItem::where('user_id', auth()->id())->where('product_variant_id', $selected->id)->value('quantity')
            : 0;

        return view('livewire.customer.shop.product-show', [
            'qty' => $this->cartQuantities()[$this->product->id] ?? 0,
            'choices' => $choices,
            'selected' => $selected,
            'choiceQty' => $choiceQty,
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
