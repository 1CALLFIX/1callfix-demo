<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceOrder;
use App\Models\Store;
use App\Services\ProviderCommercialRateResolver;

/**
 * E-commerce Phase 4 — the platform fee % on shop goods. The most specific Admin → Shop Commission rule wins:
 *   shop + category › shop › category + franchise › category + city › category › franchise › city,
 * a sub-category inheriting its parent category's rule; with no rule, the existing ProviderCommercialRateResolver
 * (negotiated seller deal › franchise platform fee › global default). An order mixing categories pays the
 * line-weighted rate. Delivery is not touched (it keeps the franchise/global rate).
 */
class MarketplaceCommissionResolver
{
    public function __construct(private ProviderCommercialRateResolver $fallback)
    {
    }

    public function rateFor(Store $store, ?int $categoryId): float
    {
        $store->loadMissing('franchise', 'provider');
        $categories = $this->categoryChain($categoryId);
        $cityId = $store->franchise?->city_id;

        $rules = MarketplaceCommissionRule::query()
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $store->id))
            ->where(fn ($q) => $q->whereNull('marketplace_category_id')->orWhereIn('marketplace_category_id', $categories ?: [0]))
            ->where(fn ($q) => $q->whereNull('franchise_id')->orWhere('franchise_id', $store->franchise_id))
            ->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $cityId))
            ->get();

        $best = $rules->sortByDesc(fn (MarketplaceCommissionRule $r) => $this->specificity($r, $categories))->first();
        if ($best) {
            return (float) $best->platform_fee_percent;
        }

        return $store->franchise ? $this->fallback->resolve($store->franchise, $store->provider) : 0.0;
    }

    /** Line-weighted platform fee % for an order's goods. */
    public function rateForOrder(MarketplaceOrder $order): float
    {
        $order->loadMissing('store', 'items.product');
        $total = 0.0;
        $weighted = 0.0;
        foreach ($order->items as $item) {
            $amount = (float) $item->line_total;
            $weighted += $amount * $this->rateFor($order->store, $item->product?->marketplace_category_id);
            $total += $amount;
        }

        return $total > 0 ? round($weighted / $total, 4) : $this->rateFor($order->store, null);
    }

    /** Category id first, then its parents (a sub-category inherits). */
    private function categoryChain(?int $categoryId): array
    {
        $chain = [];
        for ($id = $categoryId, $guard = 0; $id && $guard < 5; $guard++) {
            $chain[] = $id;
            $id = MarketplaceCategory::whereKey($id)->value('parent_id');
        }

        return $chain;
    }

    private function specificity(MarketplaceCommissionRule $r, array $categories): int
    {
        // The exact category beats its parent; a shop beats a category; franchise beats city.
        $categoryScore = $r->marketplace_category_id ? 10 - (int) array_search($r->marketplace_category_id, $categories, true) : 0;

        return ($r->store_id ? 1000 : 0) + ($categoryScore * 50) + ($r->franchise_id ? 20 : 0) + ($r->city_id ? 10 : 0);
    }
}
