<?php

namespace App\Livewire\Customer\Shop\Concerns;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\GuestShopCart;
use App\Support\SiteText;

/**
 * Product choices 2026-11-02 — picking a size / colour / portion and adding that choice to the cart. Shared by the
 * product page and the choice pop-up on the shop cards (2026-11-02, Swiggy / Zepto style), so both follow the same
 * rules: within the choice's stock and the product's limit per order; a visitor's cart lives in the session.
 * The using class has `public ?Product $product`, `public array $picked`, `public string $missingChoice` and
 * `public string $cartMessage`.
 */
trait PicksProductChoices
{
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

    /** Adds one of the picked choice to the cart, within its stock and the product's limit. */
    public function addChoice(): void
    {
        $this->changeChoice(+1);
    }

    public function removeChoiceOne(): void
    {
        $this->changeChoice(-1);
    }

    /** A choice with only one value on sale is picked for the customer. */
    protected function pickSingleValues(): void
    {
        foreach ($this->choiceValues() as $name => $values) {
            if (count($values) === 1) {
                $this->picked[$name] = $values[0];
            }
        }
    }

    protected function choiceQuantity(ProductVariant $variant): int
    {
        return auth()->check()
            ? (int) CartItem::where('user_id', auth()->id())->where('product_variant_id', $variant->id)->value('quantity')
            : app(GuestShopCart::class)->quantityFor($this->product->id, $variant->id);
    }

    protected function changeChoice(int $step): void
    {
        $t = fn (string $key, array $r = []) => SiteText::get($key, $r);
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
        $line = $guest ? null : CartItem::where('user_id', auth()->id())->where('product_id', $this->product->id)
            ->where('product_variant_id', $variant->id)->first();
        $wanted = $this->choiceQuantity($variant) + $step;
        $limit = min((int) $variant->stock, (int) ($this->product->maximum_cart_quantity ?: PHP_INT_MAX));
        if ($step > 0 && $wanted > $limit) {
            $this->cartMessage = $limit <= 0 ? $t('shop.product.choice_sold_out', ['choice' => $variant->name])
                : "Only {$limit} of {$this->product->name} ({$variant->name}) can be ordered.";

            return;
        }

        if ($guest) {
            app(GuestShopCart::class)->set($this->product->id, $variant->id, max(0, $wanted));
            $this->cartMessage = '';
            $this->dispatch('shop-cart-changed');

            return;
        }

        $cart = app(CartService::class);
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
    protected function variants()
    {
        $this->product->loadMissing('activeVariants');

        return $this->product->activeVariants->each->setRelation('product', $this->product);
    }

    /** choice name => values on sale, in the seller's order. */
    protected function choiceValues(): array
    {
        if (! $this->product || ! $this->product->hasChoices()) {
            return [];
        }
        $out = [];
        foreach ($this->product->option_names as $name) {
            $out[$name] = $this->variants()->map(fn ($v) => $v->options[$name] ?? null)->filter()->unique()->values()->all();
        }

        return $out;
    }

    protected function selectedVariant(): ?ProductVariant
    {
        $names = $this->product?->option_names ?? [];
        if (! $this->product || ! $this->product->hasChoices() || count(array_intersect_key($this->picked, array_flip($names))) < count($names)) {
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
    protected function stockFor(string $name, string $value): ?int
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
    protected function valueInStock(string $name, string $value): bool
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

    /** What a choice picker shows: choices (value => in stock), the picked choice, its count in the cart, "N left". */
    protected function choiceViewData(): array
    {
        $choices = [];
        foreach ($this->choiceValues() as $name => $values) {
            $choices[$name] = collect($values)->mapWithKeys(fn ($v) => [$v => $this->valueInStock($name, $v)])->all();
        }
        $selected = $this->selectedVariant();
        $lowStock = [];
        foreach ($this->choiceValues() as $name => $values) {
            foreach ($values as $value) {
                $stock = $this->stockFor($name, $value);
                if ($stock !== null && $stock > 0 && $stock <= 5) {
                    $lowStock[$name][$value] = $stock;
                }
            }
        }

        return [
            'choices' => $choices,
            'selected' => $selected,
            'choiceQty' => $selected ? $this->choiceQuantity($selected) : 0,
            'lowStock' => $lowStock,
        ];
    }
}
