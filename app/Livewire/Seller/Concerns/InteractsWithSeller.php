<?php

namespace App\Livewire\Seller\Concerns;

use App\Models\MarketplaceOrder;
use App\Models\Provider;
use App\Models\Store;
use App\Services\ModuleActivationService;

/**
 * The signed-in seller (EnsureIsSeller has already proved the account owns a shop), their shop and its scope.
 * One shop per seller through sign-up; an admin-made second shop is reached with ?store= on every page.
 */
trait InteractsWithSeller
{
    protected function seller(): Provider
    {
        return Provider::with('user')->where('user_id', auth()->id())->firstOrFail();
    }

    protected function shop(): Store
    {
        $stores = Store::where('provider_id', $this->seller()->id)->orderBy('id');
        $chosen = (int) request()->query('store', 0);

        return ($chosen ? (clone $stores)->whereKey($chosen)->first() : null) ?? $stores->firstOrFail();
    }

    protected function shopScope(Store $store): array
    {
        return array_filter([
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ]);
    }

    /** Commerce switched on (Admin → Modules) where this shop is. */
    protected function shopModuleLive(Store $store): bool
    {
        return app(ModuleActivationService::class)->isActive($store->module, $this->shopScope($store));
    }

    /** One of this seller's own orders, or 404 — never another shop's. */
    protected function ownOrder(int $orderId): MarketplaceOrder
    {
        $order = MarketplaceOrder::with(['items', 'customer', 'deliveryAddress', 'store'])->findOrFail($orderId);
        abort_unless(Store::whereKey($order->store_id)->where('provider_id', $this->seller()->id)->exists(), 404);

        return $order;
    }
}
