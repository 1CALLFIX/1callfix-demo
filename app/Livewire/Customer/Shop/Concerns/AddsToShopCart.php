<?php

namespace App\Livewire\Customer\Shop\Concerns;

use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;

/**
 * E-commerce Phase 2 — "Add" and the +/- stepper on every shop page. Browsing is public; adding needs a signed-in
 * customer (a guest goes to sign in and comes back). Quantity never goes past the stock or the product's limit per
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
        if (! auth()->check()) {
            session()->put('url.intended', url()->previous());
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        $product = Product::where('is_active', true)->where('is_approved', true)
            ->whereHas('store', fn ($q) => $q->sellable())->find($productId);
        if (! $product) {
            $this->cartMessage = 'This item is no longer available.';

            return;
        }

        $line = CartItem::where('user_id', auth()->id())->where('product_id', $product->id)->whereNull('product_variant_id')->first();
        $current = (int) ($line?->quantity ?? 0);
        $wanted = $current + $step;
        $limit = min((int) $product->stock, (int) ($product->maximum_cart_quantity ?: PHP_INT_MAX));

        if ($step > 0 && $wanted > $limit) {
            $this->cartMessage = $limit <= 0 ? "{$product->name} is out of stock." : "Only {$limit} of {$product->name} can be ordered.";

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
            : [];
    }
}
