<?php

namespace App\Livewire\Customer\Shop\Concerns;

use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;

/**
 * E-commerce Phase 2 — "Add" and the +/- stepper on every shop page. Browsing and adding are public (Shop Phase A
 * 2026-11-02: a visitor's cart lives in the session and joins their own cart at sign-in). Quantity never goes past the stock or the product's limit per
 * order; checkout re-checks stock under a lock (CreateMarketplaceOrderAction).
 */
trait AddsToShopCart
{
    public string $cartMessage = '';

    public function addToCart(int $productId): void
    {
        $this->changeQuantity($productId, +1);
    }

    public function removeOne(int $productId): void
    {
        $this->changeQuantity($productId, -1);
    }

    private function changeQuantity(int $productId, int $step): void
    {
        $product = Product::where('is_active', true)->where('is_approved', true)
            ->whereHas('store', fn ($q) => $q->publiclyVisible())->find($productId);
        if (! $product) {
            $this->cartMessage = \App\Support\SiteText::get('shop.item_gone');

            return;
        }

        if ($product->hasChoices()) {
            // Product choices 2026-11-02: a size/colour/portion is picked on the product page.
            $this->cartMessage = \App\Support\SiteText::get('shop.product.pick_first', ['choice' => mb_strtolower((string) ($product->option_names[0] ?? ''))]);

            return;
        }

        // Shop Phase A 2026-11-02: a visitor fills a cart without signing in (GuestShopCart); sign-in is at checkout.
        $guest = ! auth()->check();
        $line = $guest ? null : CartItem::where('user_id', auth()->id())->where('product_id', $product->id)->whereNull('product_variant_id')->first();
        $current = $guest ? app(\App\Services\GuestShopCart::class)->quantityFor($product->id) : (int) ($line?->quantity ?? 0);
        $wanted = $current + $step;
        $limit = min((int) $product->stock, (int) ($product->maximum_cart_quantity ?: PHP_INT_MAX));

        if ($step > 0 && $wanted > $limit) {
            $this->cartMessage = $limit <= 0 ? "{$product->name} is out of stock." : "Only {$limit} of {$product->name} can be ordered.";

            return;
        }

        if ($guest) {
            app(\App\Services\GuestShopCart::class)->set($product->id, null, max(0, $wanted));
            $this->cartMessage = '';
            $this->dispatch('shop-cart-changed');

            return;
        }

        $cart = app(CartService::class);
        if ($line === null) {
            $cart->add(auth()->user(), $product, 1);
        } elseif ($wanted <= 0) {
            $cart->remove($line);
        } else {
            $cart->updateQuantity($line, $wanted);
        }
        $this->cartMessage = '';
        $this->dispatch('shop-cart-changed');
    }

    /** product_id => quantity in this customer's cart (plain products; variants are chosen on the product page). */
    protected function cartQuantities(): array
    {
        return auth()->check()
            ? CartItem::where('user_id', auth()->id())->whereNull('product_variant_id')->pluck('quantity', 'product_id')->map(fn ($q) => (int) $q)->all()
            : app(\App\Services\GuestShopCart::class)->plainQuantities();
    }
}
