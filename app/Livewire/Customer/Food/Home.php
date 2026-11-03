<?php

namespace App\Livewire\Customer\Food;

use App\Livewire\Customer\Food\Concerns\InteractsWithFoodCity;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Store;
use App\Support\FoodSettings;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Food F3 — /{city}/food: the city's restaurants. Our own layout (owner rule: not a copy of any app): one plain list,
 * open ones first, each card saying what matters before you tap — cuisines, how long the kitchen takes today, the
 * delivery fee, pure veg. Search finds a restaurant by its name, cuisine or a dish. Closed ones stay listed at the
 * bottom with when they open. Every word is SiteText (English + Telugu); every number comes from the restaurant's
 * page or Food Settings.
 */
class Home extends Component
{
    use InteractsWithFoodCity;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'cuisine')]
    public string $cuisine = '';

    #[Url(as: 'veg')]
    public bool $vegOnly = false;

    public function mount(string $city): void
    {
        $this->enterFoodCity($city);
    }

    public function pickCuisine(string $cuisine): void
    {
        $this->cuisine = $this->cuisine === $cuisine ? '' : $cuisine;
    }

    public function render()
    {
        $term = trim($this->search);
        $restaurants = self::publicRestaurantsIn($this->foodCity)
            ->with(['hours', 'franchise'])
            ->when($this->cuisine !== '', fn ($q) => $q->where('cuisines', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->cuisine).'%'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('cuisines', 'like', "%{$term}%")
                ->orWhereHas('products', fn ($p) => $p->where('is_active', true)->where('is_approved', true)->where('name', 'like', "%{$term}%"))))
            ->withCount(['products as dishes_count' => fn ($q) => $q->where('is_active', true)->where('is_approved', true)])
            ->withCount(['products as non_veg_count' => fn ($q) => $q->where('is_active', true)->where('is_approved', true)->where(fn ($w) => $w->whereNull('food_type')->orWhere('food_type', '!=', 'veg'))])
            ->orderBy('name')->get()
            ->reject(fn (Store $s) => $s->missingFssai() || $s->dishes_count === 0)
            ->when($this->vegOnly, fn ($c) => $c->filter(fn (Store $s) => $s->non_veg_count === 0))
            ->values();

        $ratings = Review::query()->join('marketplace_orders', 'marketplace_orders.id', '=', 'reviews.marketplace_order_id')
            ->whereIn('marketplace_orders.store_id', $restaurants->pluck('id'))
            ->groupBy('marketplace_orders.store_id')
            ->selectRaw('marketplace_orders.store_id as store_id, avg(reviews.rating) as avg, count(*) as n')
            ->get()->keyBy('store_id');

        $cards = $restaurants->map(fn (Store $s) => self::card($s, $this->foodCity->slug, $ratings->get($s->id)))
            ->sortBy(fn ($c) => $c['open'] ? 0 : 1, SORT_REGULAR)->values();

        return view('livewire.customer.food.home', [
            'cards' => $cards,
            'cuisines' => FoodSettings::cuisines(),
            'city' => $this->foodCity,
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('food.meta.title', ['city' => $this->foodCity->name]),
            'metaDescription' => SiteText::get('food.meta.description', ['city' => $this->foodCity->name, 'brand' => Setting::get('branding.platform_name', '1CallFix')]),
            'indexable' => $term === '' && $this->cuisine === '' && ! $this->vegOnly && app(\App\Services\Seo\CityPages::class)->foodIndexable($this->foodCity),
        ]);
    }

    /** Restaurants taking orders right now in the city (the homepage door and the city hub). */
    public static function openCountIn(\App\Models\City $city): int
    {
        return once(fn () => self::publicRestaurantsIn($city)->where('is_open', true)->with(['hours', 'franchise'])->get()
            ->filter(fn (Store $s) => $s->isOpenNow() && ! $s->missingFssai())->count());
    }

    /** What a restaurant card shows, shared with the menu page header. */
    public static function card(Store $s, string $citySlug, $rating = null): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $open = $s->isOpenNow();
        $opens = $open ? null : $s->nextOpeningLocal();
        $scope = $s->settingsScope();
        $distanceFee = FoodSettings::decimalOrNull('food.delivery_fee_base', $scope);
        $fee = $distanceFee ?? (float) $s->delivery_fee_flat;
        $money = fn (float $v) => $symbol.rtrim(rtrim(number_format($v, 2), '0'), '.');

        return [
            'store' => $s,
            'open' => $open,
            'opensAt' => $opens ? ($opens->isSameDay($s->localNow())
                ? SiteText::get('food.opens_today', ['time' => $opens->format('g:i A')])
                : SiteText::get('food.opens_on', ['day' => $opens->translatedFormat('D'), 'time' => $opens->format('g:i A')])) : null,
            'cuisines' => implode(' · ', $s->cuisineList()),
            'minutes' => $s->currentPrepMinutes(),
            'busy' => $s->isBusy(),
            'pureVeg' => isset($s->non_veg_count) ? $s->non_veg_count === 0 : ! $s->products()->where('is_active', true)->where('is_approved', true)->where(fn ($w) => $w->whereNull('food_type')->orWhere('food_type', '!=', 'veg'))->exists(),
            'fee' => $fee > 0
                ? SiteText::get($distanceFee !== null ? 'food.fee_from' : 'food.fee', ['amount' => $money($fee)])
                : SiteText::get('food.free_delivery'),
            'freeAbove' => $s->free_delivery_above_amount !== null && $fee > 0 ? SiteText::get('food.free_above', ['amount' => $money((float) $s->free_delivery_above_amount)]) : null,
            'minimum' => (float) $s->minimum_order_amount > 0 ? SiteText::get('food.minimum', ['amount' => $money((float) $s->minimum_order_amount)]) : null,
            'costForTwo' => $s->cost_for_two !== null && (float) $s->cost_for_two > 0 ? SiteText::get('food.cost_for_two', ['amount' => $money((float) $s->cost_for_two)]) : null,
            'rating' => $rating && $rating->n > 0 ? ['avg' => number_format((float) $rating->avg, 1), 'n' => (int) $rating->n] : null,
            'url' => route('customer.food.restaurant', [$citySlug, $s->slug]),
        ];
    }
}
