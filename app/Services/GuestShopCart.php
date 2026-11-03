<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shop Phase A 2026-11-02 (owner: "like Amazon / Flipkart / Meesho"): a visitor can fill a shop cart without
 * signing in. It lives in the session only — no row, no price, no stock is held. Sign-in is asked at checkout; the
 * moment the visitor signs in (any way: password, OTP, Google), MergeGuestShopCart moves these lines into their
 * real cart (CartService::add, same merging and limits), and the session copy is cleared.
 *
 * Lines are kept as [product_id, variant_id, quantity, add_on_ids] under small integer keys (the bag's +/− and Remove
 * use them). Food F3: a dish with different add-ons is its own line ("Paneer pizza + cheese" and plain are two lines).
 */
class GuestShopCart
{
    private const KEY = 'guest_shop_cart';

    private const MAX_LINES = 50;

    /** @return array<int, array{product_id:int, variant_id:?int, quantity:int, add_on_ids?:int[]}> */
    public function raw(): array
    {
        $raw = session(self::KEY, []);

        return is_array($raw) ? $raw : [];
    }

    public function count(): int
    {
        return (int) collect($this->raw())->sum('quantity');
    }

    public function quantityFor(int $productId, ?int $variantId = null, array $addOnIds = []): int
    {
        $key = $this->keyFor($productId, $variantId, $addOnIds);

        return $key === null ? 0 : (int) $this->raw()[$key]['quantity'];
    }

    /** product_id => quantity for plain (no-choice) products, as the shop cards show it. */
    public function plainQuantities(): array
    {
        return collect($this->raw())->whereNull('variant_id')->filter(fn ($l) => empty($l['add_on_ids']))
            ->mapWithKeys(fn ($l) => [$l['product_id'] => (int) $l['quantity']])->all();
    }

    /** Food F3: product_id => quantity across all its lines (any choice, any add-ons), for the menu's counters. */
    public function totalsByProduct(): array
    {
        return collect($this->raw())->groupBy('product_id')->map(fn ($ls) => (int) $ls->sum('quantity'))->all();
    }

    /** Food F3: removes every line of these products (one restaurant per food cart). */
    public function removeProducts(array $productIds): void
    {
        $productIds = array_map('intval', $productIds);
        session([self::KEY => array_filter($this->raw(), fn ($l) => ! in_array((int) $l['product_id'], $productIds, true))]);
    }

    /** Sets the quantity of one product / choice / set of add-ons (0 removes it). */
    public function set(int $productId, ?int $variantId, int $quantity, array $addOnIds = []): void
    {
        $raw = $this->raw();
        $addOnIds = self::normalIds($addOnIds);
        $key = $this->keyFor($productId, $variantId, $addOnIds);
        if ($quantity <= 0) {
            if ($key !== null) {
                unset($raw[$key]);
            }
        } elseif ($key !== null) {
            $raw[$key]['quantity'] = $quantity;
        } elseif (count($raw) < self::MAX_LINES) {
            $raw[(int) (collect(array_keys($raw))->max() ?? 0) + 1] = ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity, 'add_on_ids' => $addOnIds];
        }
        session([self::KEY => $raw]);
    }

    public function setByKey(int $key, int $quantity): void
    {
        $line = $this->raw()[$key] ?? null;
        if ($line) {
            $this->set((int) $line['product_id'], $line['variant_id'] !== null ? (int) $line['variant_id'] : null, $quantity, $line['add_on_ids'] ?? []);
        }
    }

    /**
     * The lines as unsaved CartItem models (so the bag draws a guest's cart exactly like a signed-in one). The model
     * id is the session key; nothing is ever saved from here.
     */
    public function lines(): Collection
    {
        $raw = $this->raw();
        if ($raw === []) {
            return collect();
        }
        $products = Product::whereIn('id', array_column($raw, 'product_id'))->get()->keyBy('id');
        $variants = ProductVariant::whereIn('id', array_filter(array_column($raw, 'variant_id')))->get()->keyBy('id');

        return collect($raw)->map(function ($l, $key) use ($products, $variants) {
            $product = $products->get($l['product_id']);
            if (! $product) {
                return null;
            }
            $variant = $l['variant_id'] ? $variants->get($l['variant_id']) : null;
            $variant?->setRelation('product', $product);
            $item = new CartItem([
                'store_id' => $product->store_id, 'product_id' => $product->id,
                'product_variant_id' => $variant?->id, 'quantity' => (int) $l['quantity'],
                'add_on_ids' => self::normalIds($l['add_on_ids'] ?? []),
            ]);
            $item->id = (int) $key;
            $item->setRelation('product', $product);
            $item->setRelation('variant', $variant);
            $item->unit_price_snapshot = $item->unitPrice();

            return $item;
        })->filter()->values();
    }

    /** Moves the guest lines into this customer's cart (on sign-in), within stock and each product's limit. */
    public function mergeInto(User $user): void
    {
        $cart = app(CartService::class);
        $lines = $this->lines();
        // Food F3: one restaurant per food cart. The dishes just picked win over an older food cart elsewhere.
        $guestRestaurants = $lines->map(fn ($l) => $l->product)->filter(fn ($p) => $p && $p->store?->isRestaurant())->pluck('store_id')->unique();
        if ($guestRestaurants->isNotEmpty()) {
            app(\App\Services\Food\FoodCart::class)->clearOtherRestaurants($user, (int) $guestRestaurants->last());
        }
        foreach ($lines as $line) {
            $product = $line->product;
            $variant = $line->variant;
            if (! $product->is_active || ! $product->is_approved || ($line->product_variant_id && (! $variant || ! $variant->is_active))) {
                continue;
            }
            if ($product->store?->isRestaurant()) {
                // Food F3: dishes have no stock; the per-dish limit and the add-on rules are checked again at checkout.
                $food = app(\App\Services\Food\FoodCart::class);
                $add = min((int) $line->quantity, $food->limitFor($product) - $food->quantityOf($user, $product->id));
                if ($add > 0) {
                    $cart->add($user, $product, $add, $variant, $line->add_on_ids ?? []);
                }

                continue;
            }
            $existing = (int) CartItem::where('user_id', $user->id)->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)->value('quantity');
            $limit = min((int) ($variant?->stock ?? $product->stock), (int) ($product->maximum_cart_quantity ?: PHP_INT_MAX));
            $add = min((int) $line->quantity, $limit - $existing);
            if ($add > 0) {
                $cart->add($user, $product, $add, $variant);
            }
        }
        session()->forget(self::KEY);
    }

    private function keyFor(int $productId, ?int $variantId, array $addOnIds = []): ?int
    {
        $addOnIds = self::normalIds($addOnIds);
        foreach ($this->raw() as $key => $l) {
            if ((int) $l['product_id'] === $productId && ($l['variant_id'] !== null ? (int) $l['variant_id'] : null) === $variantId
                && self::normalIds($l['add_on_ids'] ?? []) === $addOnIds) {
                return (int) $key;
            }
        }

        return null;
    }

    /** @return int[] sorted, unique */
    private static function normalIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
