<?php

namespace App\Services\Food;

use App\Exceptions\FoodCartConflict;
use App\Models\AddOn;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CartService;
use App\Services\GuestShopCart;
use App\Support\FoodSettings;
use App\Support\Modules;
use App\Support\SiteText;

/**
 * Food F3 — adding dishes to the one cart (/cart) from a restaurant's menu, for a signed-in customer (cart_items)
 * and a visitor (GuestShopCart, session). Food rules on top of the shop cart:
 *  - one restaurant per food cart: dishes from a second restaurant ask "Start a new cart?" first (FoodCartConflict);
 *    the shop lines are never touched;
 *  - dishes have no stock: a per-dish limit (the dish's own "Limit per order", else Food Settings);
 *  - the dish must be on the menu now (shown, approved, not sold out) and the restaurant open;
 *  - a portion (Half / Full) when the dish has choices, and the add-ons each follow their group's rule.
 * Checkout checks all of it again (CreateMarketplaceOrderAction → FoodCheckout): the menu can change meanwhile.
 */
class FoodCart
{
    public function __construct(private CartService $cart, private GuestShopCart $guest)
    {
    }

    /**
     * @param  int[]  $addOnIds
     *
     * @throws FoodCartConflict when the food cart holds another restaurant's dishes and $replace is false
     * @throws \RuntimeException with a customer message (SiteText) when the dish cannot be added
     */
    public function add(?User $user, Product $product, ?ProductVariant $variant = null, array $addOnIds = [], bool $replace = false): void
    {
        $product = $this->onMenu($product->id);
        $store = $product->store;
        if (! $store->isOpenNow() || $store->missingFssai()) {
            throw new \RuntimeException(SiteText::get('food.cart.closed', ['restaurant' => $store->name]));
        }

        if ($product->hasChoices()) {
            if (! $variant || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active) {
                throw new \RuntimeException(SiteText::get('food.cart.pick_portion'));
            }
        } else {
            $variant = null;
        }

        $addOnIds = $this->validAddOns($product, $addOnIds);

        $other = $this->otherRestaurantName($user, (int) $store->id);
        if ($other !== null) {
            if (! $replace) {
                throw new FoodCartConflict($other, $store->name);
            }
            $this->clearOtherRestaurants($user, (int) $store->id);
        }

        if ($this->quantityOf($user, $product->id) + 1 > $this->limitFor($product)) {
            throw new \RuntimeException(SiteText::get('food.cart.limit', ['count' => $this->limitFor($product), 'dish' => $product->name]));
        }

        if ($user === null) {
            $this->guest->set($product->id, $variant?->id, $this->guest->quantityFor($product->id, $variant?->id, $addOnIds) + 1, $addOnIds);

            return;
        }
        $this->cart->add($user, $product, 1, $variant, $addOnIds);
    }

    /** One more of a plain dish (no portion, no add-on groups): the menu's "+". */
    public function addPlain(?User $user, Product $product, bool $replace = false): void
    {
        $this->add($user, $product, null, [], $replace);
    }

    /** One less of this dish: from its newest line (the menu's "−"). */
    public function removeOne(?User $user, int $productId): void
    {
        if ($user === null) {
            $line = collect($this->guest->raw())->filter(fn ($l) => (int) $l['product_id'] === $productId)->keys()->last();
            if ($line !== null) {
                $this->guest->setByKey((int) $line, max(0, (int) $this->guest->raw()[$line]['quantity'] - 1));
            }

            return;
        }
        $line = CartItem::where('user_id', $user->id)->where('product_id', $productId)->latest('id')->first();
        if ($line) {
            $this->cart->updateQuantity($line, $line->quantity - 1);
        }
    }

    /** product_id => quantity across all lines, for the menu's counters. */
    public function quantities(?User $user): array
    {
        if ($user === null) {
            return $this->guest->totalsByProduct();
        }

        return CartItem::where('user_id', $user->id)->selectRaw('product_id, sum(quantity) as q')->groupBy('product_id')
            ->pluck('q', 'product_id')->map(fn ($q) => (int) $q)->all();
    }

    public function quantityOf(?User $user, int $productId): int
    {
        return (int) ($this->quantities($user)[$productId] ?? 0);
    }

    /** The dish's own limit per order, else Food Settings → Most of one dish in a cart. */
    public function limitFor(Product $product): int
    {
        if ($product->maximum_cart_quantity) {
            return (int) $product->maximum_cart_quantity;
        }

        return max(1, (int) FoodSettings::get('food.max_per_dish', $product->store?->settingsScope() ?? []));
    }

    /** The name of another restaurant whose dishes are in this cart, or null. */
    public function otherRestaurantName(?User $user, int $storeId): ?string
    {
        $storeIds = $this->foodStoreIdsInCart($user)->reject(fn ($id) => $id === $storeId);

        return $storeIds->isEmpty() ? null : (string) \App\Models\Store::whereKey($storeIds->first())->value('name');
    }

    /** Removes every dish of other restaurants (shop items stay). */
    public function clearOtherRestaurants(?User $user, int $keepStoreId): void
    {
        $others = $this->foodStoreIdsInCart($user)->reject(fn ($id) => $id === $keepStoreId);
        if ($others->isEmpty()) {
            return;
        }
        if ($user === null) {
            $this->guest->removeProducts(Product::whereIn('store_id', $others)->pluck('id')->all());

            return;
        }
        CartItem::where('user_id', $user->id)->whereIn('store_id', $others)->delete();
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function foodStoreIdsInCart(?User $user)
    {
        $storeIds = $user === null
            ? Product::whereIn('id', collect($this->guest->raw())->pluck('product_id'))->pluck('store_id')
            : CartItem::where('user_id', $user->id)->pluck('store_id');

        return \App\Models\Store::whereIn('id', $storeIds->unique())->where('module', Modules::FOOD)->pluck('id')->map(fn ($id) => (int) $id);
    }

    private function onMenu(int $productId): Product
    {
        $product = Product::where('is_active', true)->where('is_approved', true)
            ->whereHas('store', fn ($q) => $q->publiclyVisible()->where('module', Modules::FOOD))
            ->with('store')->find($productId);
        if (! $product) {
            throw new \RuntimeException(SiteText::get('food.cart.dish_gone'));
        }
        if ($product->isSoldOutNow()) {
            throw new \RuntimeException(SiteText::get('food.cart.sold_out', ['dish' => $product->name]));
        }

        return $product;
    }

    /**
     * Only this dish's active add-ons, and each group's rule kept ("Choose your base: pick 1").
     *
     * @return int[] sorted
     */
    private function validAddOns(Product $product, array $addOnIds): array
    {
        $groups = $product->addOnGroups()->where('add_on_groups.is_active', true)->get();
        $chosen = collect($addOnIds)->map(fn ($id) => (int) $id)->unique()->values();
        $allowed = AddOn::whereIn('add_on_group_id', $groups->pluck('id'))->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id);
        if ($chosen->diff($allowed)->isNotEmpty()) {
            throw new \RuntimeException(SiteText::get('food.cart.choice_gone'));
        }
        foreach ($groups as $group) {
            if ($group->problemWith($chosen->all()) !== null) {
                throw new \RuntimeException(SiteText::get('food.cart.group_rule', ['group' => $group->name, 'rule' => self::ruleText($group)]));
            }
        }

        return $chosen->sort()->values()->all();
    }

    /** The group's rule in the customer's language: "Pick 1", "Pick 2 to 4", "Optional, up to 3". */
    public static function ruleText(\App\Models\AddOnGroup $group): string
    {
        return match (true) {
            $group->min_select === 0 => SiteText::get('food.addons.optional_up_to', ['count' => $group->max_select]),
            $group->min_select === $group->max_select => SiteText::get('food.addons.pick', ['count' => $group->min_select]),
            default => SiteText::get('food.addons.pick_range', ['min' => $group->min_select, 'max' => $group->max_select]),
        };
    }
}
