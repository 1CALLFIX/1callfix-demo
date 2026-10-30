<?php

namespace App\Services\Customer;

use App\Livewire\Customer\Shop\Home as ShopHome;
use App\Models\Product;
use App\Models\Store;
use App\Services\Seo\CityContext;
use App\Support\Modules;
use App\Support\Seo\QaRows;
use Illuminate\Support\Collection;

/**
 * One search (owner 2026-10-30): shop items and shops in the visitor's city, used by the top-bar search box and the
 * search results page. Only where the shop is on in that city (Admin → Modules), and the same rules as the shop
 * pages: switched-on, approved products of approved, active shops; QA shops hidden on production.
 */
class ShopSearch
{
    /** @return array{city: ?string, products: Collection, shops: Collection} */
    public function search(string $term, int $productLimit, int $shopLimit): array
    {
        $city = app(CityContext::class)->current();
        if (! $city || mb_strlen(trim($term)) < 2 || ! ShopHome::shopLiveIn($city)) {
            return ['city' => null, 'products' => collect(), 'shops' => collect()];
        }
        $like = '%'.trim($term).'%';
        $stores = fn ($q) => $q->sellable()->where('module', Modules::COMMERCE)
            ->whereHas('franchise', fn ($f) => $f->where('city_id', $city->id))
            ->when(QaRows::hiding(), fn ($s) => $s->where('name', 'not like', QaRows::PREFIX.'%'));

        return [
            'city' => $city->slug,
            'products' => Product::query()->where('is_active', true)->where('is_approved', true)
                ->where('name', 'like', $like)->whereHas('store', $stores)
                ->with('store:id,slug,name')->orderByDesc('stock')->orderBy('name')->limit($productLimit)->get(),
            'shops' => $stores(Store::query())->where('name', 'like', $like)
                ->orderByDesc('is_open')->orderBy('name')->limit($shopLimit)->get(),
        ];
    }
}
