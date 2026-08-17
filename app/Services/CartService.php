<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 24 (Marketplace Foundation). A browse-time convenience, NOT the
 * concurrency-safety boundary -- same caveat `PropertyAvailabilityService::
 * isAvailable()`'s own docblock states for itself: only
 * `CreateMarketplaceOrderAction`'s stock lock (inside the checkout
 * transaction) is the authoritative safety check. Real evidence: 6amMart's
 * own cart rows are flat line items (no separate Cart "header"), scoped
 * per `store_id` -- a user can hold simultaneous carts across different
 * stores. `unit_price_snapshot` is fixed at add-time (matching real
 * evidence); add-on prices are deliberately NOT snapshotted the same way
 * -- they're looked up live from `add_ons.price` at both display and
 * checkout time, a reasonable, documented simplification (add-ons are
 * small, store-controlled reference prices, not the customer's actual
 * purchase-price commitment the base snapshot protects).
 */
class CartService
{
    /**
     * Adds a line, or merges into an existing identical line (same
     * product+variant+add-on set) by incrementing its quantity -- avoids
     * silently duplicating rows for the same exact item.
     */
    public function add(User $user, Product $product, int $quantity, ?ProductVariant $variant = null, array $addOnIds = []): CartItem
    {
        if ($quantity < 1) {
            throw new \RuntimeException('Quantity must be at least 1.');
        }

        sort($addOnIds);

        $existing = CartItem::where('user_id', $user->id)
            ->where('store_id', $product->store_id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->get()
            ->first(function (CartItem $item) use ($addOnIds) {
                $itemAddOnIds = collect($item->add_on_ids ?? [])->sort()->values()->all();

                return $itemAddOnIds === $addOnIds;
            });

        if ($existing) {
            $existing->quantity += $quantity;
            $existing->save();

            return $existing;
        }

        $unitPrice = $variant ? $variant->effectivePrice() : $product->effective_price;

        return CartItem::create([
            'user_id' => $user->id,
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
            'add_on_ids' => $addOnIds,
            'unit_price_snapshot' => $unitPrice,
        ]);
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function listForStore(User $user, int $storeId): Collection
    {
        return CartItem::where('user_id', $user->id)->where('store_id', $storeId)->with(['product', 'variant'])->get();
    }

    public function clearForStore(User $user, int $storeId): void
    {
        CartItem::where('user_id', $user->id)->where('store_id', $storeId)->delete();
    }

    /** The live add-ons total for one cart line (see class docblock -- deliberately not snapshotted). */
    public function addOnsTotalFor(CartItem $item): float
    {
        if (empty($item->add_on_ids)) {
            return 0.0;
        }

        return (float) AddOn::whereIn('id', $item->add_on_ids)->sum('price');
    }

    /** Subtotal for a store's cart -- (unit_price + live add-ons total) * quantity per line, summed. */
    public function subtotalForStore(User $user, int $storeId): float
    {
        return $this->listForStore($user, $storeId)->sum(
            fn (CartItem $item) => ((float) $item->unit_price_snapshot + $this->addOnsTotalFor($item)) * $item->quantity
        );
    }
}
