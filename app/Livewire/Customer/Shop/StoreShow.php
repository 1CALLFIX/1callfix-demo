<?php

namespace App\Livewire\Customer\Shop;

use App\Livewire\Customer\Shop\Concerns\AddsToShopCart;
use App\Livewire\Customer\Shop\Concerns\InteractsWithShopCity;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Store;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** E-commerce Phase 2 — /{city}/shop/{shop}: one shop, its terms up front (minimum order, delivery fee), its items. */
class StoreShow extends Component
{
    use AddsToShopCart;
    use InteractsWithShopCity;
    use WithPagination;

    public Store $store;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public ?int $categoryId = null;

    public function mount(string $city, string $storeSlug): void
    {
        if (! $this->enterShopCity($city)) {
            return;
        }
        $this->store = $this->cityStores()->where('slug', $storeSlug)->firstOrFail();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $base = Product::where('store_id', $this->store->id)->where('is_active', true)->where('is_approved', true);
        $term = trim($this->search);
        $rating = Review::whereHas('marketplaceOrder', fn ($q) => $q->where('store_id', $this->store->id))->selectRaw('avg(rating) as avg, count(*) as n')->first();

        return view('livewire.customer.shop.store-show', [
            'products' => (clone $base)
                ->when($this->categoryId, fn ($q) => $q->where('marketplace_category_id', $this->categoryId))
                ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
                ->with('store:id,name,slug,is_open')->orderByRaw('stock > 0 desc')->orderBy('name')->paginate(30),
            'categories' => MarketplaceCategory::whereIn('id', (clone $base)->whereNotNull('marketplace_category_id')->distinct()->pluck('marketplace_category_id'))
                ->orderBy('position')->orderBy('name')->get(['id', 'name']),
            'rating' => $rating,
            'inCart' => $this->cartQuantities(),
            'city' => $this->shopCity,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', [
            'title' => $this->store->name.' — shop in '.$this->shopCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit($this->store->description ?: \App\Support\SiteText::get('shop.meta.store_description', ['shop' => $this->store->name, 'city' => $this->shopCity->name, 'brand' => Setting::get('branding.platform_name', '1CallFix')]), 155),
            'ogImage' => $this->store->logo_url,
            // City URL architecture: never a test shop or an empty one.
            'indexable' => app(\App\Services\Seo\CityPages::class)->storeIndexable($this->store),
            'schema' => [$this->schema($rating)],
        ]);
    }

    /**
     * Owner 2026-11-23: Google's "local shop" card for the shop page (restaurants and hotels already had theirs), built
     * from what the shop already filled in, so no SEO fields for owners. Real data only: the rating only when there are
     * real reviews (the same ones the page shows), hours only when saved. The shop's phone is never published.
     *
     * @return array<string, mixed>
     */
    private function schema($rating): array
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $hours = $this->store->hours()->get()->map(fn ($h) => [
            '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days[$h->day] ?? null,
            'opens' => substr((string) $h->opens_at, 0, 5), 'closes' => substr((string) $h->closes_at, 0, 5),
        ])->filter(fn ($h) => $h['dayOfWeek'] !== null)->values()->all();
        $lat = is_numeric($this->store->lat) ? (float) $this->store->lat : null;
        $lng = is_numeric($this->store->lng) ? (float) $this->store->lng : null;

        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'Store', 'name' => $this->store->name,
            'url' => url()->current(),
            'image' => $this->store->logo_url ? \App\Support\Seo::absoluteUrl($this->store->logo_url) : null,
            'description' => $this->store->description ? \Illuminate\Support\Str::limit(strip_tags((string) $this->store->description), 300) : null,
            'address' => array_filter([
                '@type' => 'PostalAddress', 'streetAddress' => trim((string) $this->store->address_line) ?: null,
                'addressLocality' => $this->shopCity->name, 'addressCountry' => 'IN',
            ]),
            'geo' => $lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0) ? ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng] : null,
            'openingHoursSpecification' => $hours ?: null,
            'aggregateRating' => $rating && (int) $rating->n > 0
                ? ['@type' => 'AggregateRating', 'ratingValue' => round((float) $rating->avg, 1), 'reviewCount' => (int) $rating->n] : null,
        ], fn ($v) => $v !== null);
    }
}
