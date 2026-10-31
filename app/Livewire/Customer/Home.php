<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\Faq;
use App\Models\HomeSpotlight;
use App\Models\Plan;
use App\Models\ServiceCategory;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Customer homepage — the marketplace discovery screen (Phase C).
 *
 * ── What is real and what is not ──────────────────────────────────────────
 * Every figure, label, badge, price and banner on this screen comes from the
 * database. Nothing is hard-coded sample data and nothing is shown that
 * would need fabricating. Concretely:
 *
 *  - Both banner slots render `banners` rows through Banner::scopeForSlot().
 *    No banner content is authored in Blade. When a slot has no live banner
 *    the hero falls back to a plain search-and-browse panel and the mid-page
 *    strip renders nothing at all — never an empty carousel shell.
 *  - "Most booked" is a real count over the `bookings` table, franchise-
 *    scoped to the viewer, and the whole section is HIDDEN when the catalog
 *    has no booking history. It is never an arbitrary ordering relabelled as
 *    popularity — see ServiceCatalogQuery::mostBooked().
 *  - Ratings come from real reviews joined through bookings; an unrated
 *    service shows no rating rather than zero stars.
 *  - Offers are real, currently-active, scope-covering flash sales. The
 *    section disappears entirely when nothing is on offer.
 *  - The membership strip lists real active `customer_membership` plans and
 *    is hidden when none are configured. It links to the Phase E screen
 *    rather than pretending to sell anything here.
 *  - There is still no review carousel and no "10,000+ happy customers"
 *    figure anywhere on this page. No verified source for such a number
 *    exists and inventing one would be fabricated marketing data.
 *
 * ── Query cost ────────────────────────────────────────────────────────────
 * Four service rails render on this page. Each goes through
 * CatalogPresenter::cards(), which batches badges, flash-sale pricing and
 * review aggregates for the whole rail — so a rail costs a fixed handful of
 * queries regardless of how many cards it holds, rather than three per card.
 */
class Home extends Component
{
    use ResolvesCatalogContext;

    private const RAIL_LIMIT = 8;
    private const COLLECTION_CATEGORY_LIMIT = 4;
    private const COLLECTION_SERVICE_LIMIT = 4;
    private const FAQ_LIMIT = 6;

    public function render()
    {
        $location = $this->location();
        $catalog = $this->catalog();
        $zone = $location->zone();
        $parcelScope = $zone ? array_filter(['zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id, 'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id]) : [];
        $parcelActive = $zone !== null && app(\App\Services\ModuleActivationService::class)->isActive(\App\Support\Modules::PARCEL, $parcelScope);

        return view('livewire.customer.home', [
            'activeZone' => $zone,
            'parcelActive' => $parcelActive,
            // "from ₹…" on the parcel card: the browsed area's Parcel Settings base fare; null (unset) hides it.
            'parcelFrom' => $parcelFrom = $parcelActive ? \App\Services\Parcel\ParcelSettings::get('parcel.base_fare', $parcelScope) : null,
            'doors' => $this->doors($parcelActive, $parcelFrom),
            'cards' => \App\Support\HomeLayout::shown('module_cards') ? $this->moduleCards($parcelActive, $parcelFrom, $parcelScope) : [],
            'sections' => \App\Support\HomeLayout::visible(),
            'heroBanners' => $this->bannersFor('top'),
            'midBanners' => $this->bannersFor('mid'),
            'categories' => $catalog->categories()->limit(\App\Support\SiteControls::categoryLimit())->get(),
            'newServices' => $newServices = $this->cardsFrom($catalog->newest(), self::RAIL_LIMIT),
            'mostBooked' => $mostBooked = $this->cardsFrom($catalog->mostBooked($location->franchiseId()), self::RAIL_LIMIT),
            'spotlight' => $this->spotlight($mostBooked, $newServices),
            'offers' => $this->offers(),
            'collections' => $this->collections(),
            'membershipPlans' => $this->membershipPlans(),
            'faqs' => $this->faqs(),
            'currencySymbol' => $this->presenter()->currencySymbol(),
        ])->layout('components.layouts.customer', [
            // Admin → SEO → Search & social controls the home title and description; the old wording is the fallback.
            'title' => \App\Services\Seo\SeoSettings::homeTitle() ?? 'Home services, on call',
            'rawTitle' => \App\Services\Seo\SeoSettings::homeTitle() !== null,
            'metaDescription' => \App\Services\Seo\SeoSettings::homeDescription(),
            'indexable' => true,
            'schema' => \App\Services\Seo\SeoSettings::homeSchema(app(\App\Services\BrandingAssetService::class)->url('logo_display_path')),
        ]);
    }

    /**
     * "Explore 1CallFix" doors (owner 2026-10-30): one per business live for this visitor, with real live numbers
     * (services listed, shops open now, the parcel base fare). Layout and words are the Super Admin's (HomeDoors).
     *
     * @return list<array>
     */
    private function doors(bool $parcelActive, $parcelFrom): array
    {
        if (! \App\Support\HomeDoors::show()) {
            return [];
        }
        $live = ['services' => ['url' => route('customer.categories.index'), 'count' => $this->catalog()->services()->count()]];
        if ($parcelActive) {
            $live['parcel'] = ['url' => route('customer.parcels.create'),
                'price' => is_numeric($parcelFrom) && (float) $parcelFrom > 0 ? $this->presenter()->currencySymbol().rtrim(rtrim(number_format((float) $parcelFrom, 2), '0'), '.') : null];
        }
        if ($city = $this->shopCity()) {
            $live['shop'] = ['url' => route('customer.shop.home', $city->slug), 'count' => $this->shopsOpen($city)];
        }

        return \App\Support\HomeDoors::doors($live);
    }

    /** The visitor's city when the shop is on there, else null. */
    private function shopCity(): ?\App\Models\City
    {
        $city = app(\App\Services\Seo\CityContext::class)->current();

        return $city && \App\Livewire\Customer\Shop\Home::shopLiveIn($city) ? $city : null;
    }

    private function shopStores(\App\Models\City $city)
    {
        return \App\Models\Store::query()->sellable()->where('module', \App\Support\Modules::COMMERCE)
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id))
            ->when(\App\Support\Seo\QaRows::hiding(), fn ($q) => $q->where('name', 'not like', \App\Support\Seo\QaRows::PREFIX.'%'));
    }

    private function shopsOpen(\App\Models\City $city): int
    {
        return once(fn () => $this->shopStores($city)->where('is_open', true)->count());
    }

    /**
     * Business cards (owner samples 2026-10-31): a card per service category (its subcategories as icons, or its
     * services when it has fewer than two subcategories), a Parcel card and a Shop card, each only where that
     * business is live and has something real to show. Order, on/off, icons per card and the offer lines are the
     * Super Admin's (HomeCards); the offer line falls back to a real fact (a live offer, shops open now, the base
     * fare) or nothing — never an invented claim.
     *
     * @return list<array{key: string, title: string, url: string, line: ?string, tiles: list<array>}>
     */
    private function moduleCards(bool $parcelActive, $parcelFrom, array $parcelScope): array
    {
        $cards = [];
        $n = \App\Support\HomeCards::icons();
        $text = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
        $newSince = \App\Support\HomeCards::newDays() > 0 ? now()->subDays(\App\Support\HomeCards::newDays()) : null;
        $isNew = fn ($row) => $newSince && $row->created_at && $row->created_at->gte($newSince);

        $limit = \App\Support\HomeCards::categoryLimit();
        $categories = $limit > 0 ? $this->catalog()->categories()
            ->whereHas('services', fn ($q) => $q->where('is_active', true))->limit($limit)->get() : collect();
        $offerIds = $categories->isNotEmpty()
            ? app(\App\Services\FlashSaleService::class)->activeServiceIdsFor($this->location()->viewerScope()) : collect();

        foreach ($categories->values() as $i => $category) {
            $key = 'category_'.$category->id;
            if (! \App\Support\HomeCards::cardShown($key)) {
                continue;
            }
            $subIds = $this->catalog()->services(['category_id' => $category->id])->pluck('subcategory_id')->filter()->unique();
            $subs = \App\Models\ServiceSubcategory::query()->whereIn('id', $subIds)->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')->limit($n)->get();
            $tint = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $category->color) ? $category->color.'26' : null;

            $tiles = $subs->count() >= 2
                ? $subs->map(fn ($sub) => ['name' => $sub->name, 'url' => \App\Support\Seo\PublicUrl::category($category, null, ['sub' => $sub->id]),
                    'image' => $sub->image_url, 'tint' => $tint, 'new' => $isNew($sub)])->all()
                : $this->cardsFrom($this->catalog()->services(['category_id' => $category->id]), $n)
                    ->map(fn ($c) => ['name' => $c['name'], 'url' => $c['url'], 'image' => $c['image_url'], 'tint' => $tint, 'new' => $isNew($c['service'])])->all();
            if ($tiles === []) {
                continue;
            }
            $hasOffer = $offerIds->isNotEmpty() && $this->catalog()->services(['category_id' => $category->id])->whereIn('id', $offerIds)->exists();

            $cards[] = ['key' => $key, 'order' => \App\Support\HomeCards::cardOrder($key, $i), 'title' => $category->name,
                'url' => \App\Support\Seo\PublicUrl::category($category), 'tiles' => $tiles,
                'line' => \App\Support\HomeCards::line($key) ?? ($hasOffer ? $text('home.card.offer_line', ['name' => $category->name]) : null),
                'line_url' => $hasOffer && \App\Support\HomeCards::line($key) === null ? route('customer.offers') : \App\Support\Seo\PublicUrl::category($category)];
        }

        if ($parcelActive && \App\Support\HomeCards::cardShown('parcel')) {
            $price = is_numeric($parcelFrom) && (float) $parcelFrom > 0
                ? $this->presenter()->currencySymbol().rtrim(rtrim(number_format((float) $parcelFrom, 2), '0'), '.') : null;
            $tiles = [['name' => $text('home.card.parcel.send'), 'url' => route('customer.parcels.create'), 'icon' => 'bolt']];
            if (\App\Services\Parcel\ParcelSettings::scheduleAvailable($parcelScope)) {
                $tiles[] = ['name' => $text('home.card.parcel.schedule'), 'url' => route('customer.parcels.create', ['when' => 'scheduled']), 'icon' => 'clock'];
            }
            $tiles[] = ['name' => $text('home.card.parcel.track'), 'url' => route('customer.parcels.index'), 'icon' => 'map-pin'];
            $cards[] = ['key' => 'parcel', 'order' => \App\Support\HomeCards::cardOrder('parcel'), 'title' => $text('home.card.parcel.title'),
                'url' => route('customer.parcels.create'), 'tiles' => $tiles, 'line_url' => route('customer.parcels.create'),
                'line' => \App\Support\HomeCards::line('parcel') ?? ($price ? $text('home.card.parcel.line_price', ['price' => $price]) : $text('home.card.parcel.line'))];
        }

        if (($city = $this->shopCity()) && \App\Support\HomeCards::cardShown('shop')) {
            // Top-level shop categories that have something sellable in this city (or under a child category).
            $sellable = \App\Models\Product::query()->where('is_active', true)->where('is_approved', true)
                ->whereHas('store', fn ($q) => $q->whereIn('stores.id', $this->shopStores($city)->select('stores.id')))
                ->whereNotNull('marketplace_category_id')->distinct()->pluck('marketplace_category_id');
            $parents = \App\Models\MarketplaceCategory::query()->whereIn('id', $sellable)->pluck('parent_id', 'id');
            $topIds = $parents->map(fn ($parent, $id) => $parent ?: $id)->unique()->values();
            $shopCats = \App\Models\MarketplaceCategory::query()->whereIn('id', $topIds)->where('is_active', true)
                ->orderBy('position')->orderBy('name')->limit($n)->get();
            $tiles = $shopCats->map(fn ($c) => ['name' => $c->name, 'url' => route('customer.shop.home', [$city->slug, 'category' => $c->slug]),
                'image' => $c->image ? (\Illuminate\Support\Str::startsWith($c->image, ['http://', 'https://', '//']) ? $c->image : \Illuminate\Support\Facades\Storage::disk('public')->url($c->image)) : null,
                'tint' => null, 'new' => $isNew($c)])->all();
            if ($tiles !== []) {
                $open = $this->shopsOpen($city);
                $cards[] = ['key' => 'shop', 'order' => \App\Support\HomeCards::cardOrder('shop'), 'title' => $text('home.card.shop.title'),
                    'url' => route('customer.shop.home', $city->slug), 'tiles' => $tiles, 'line_url' => route('customer.shop.home', $city->slug),
                    'line' => \App\Support\HomeCards::line('shop') ?? match (true) {
                        $open === 1 => $text('home.card.shop.live_one'),
                        $open > 1 => $text('home.card.shop.live', ['count' => $open]),
                        default => $text('home.card.shop.line'),
                    }];
            }
        }

        usort($cards, fn ($a, $b) => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return $cards;
    }

    /**
     * REF 1CF-HOME-SPOTLIGHT-001 — tiles for the home collage. Admin-curated
     * slots (Home Spotlight screen) first, in number order; each is a service
     * or a whole category and is skipped when its target is no longer live.
     * Fewer than four tiles are topped up from the automatic pool (most
     * booked, then newest) so the grid never shows blanks; with nothing
     * curated at all this is exactly the previous automatic collage. The
     * result is normalised to 0, 2, 4 or 6 tiles so the grid stays full.
     *
     * @return Collection<int, array{name: string, url: string, image_url: ?string, badge: ?string}>
     */
    private function spotlight(Collection $mostBooked, Collection $newServices): Collection
    {
        $auto = collect($mostBooked)->concat($newServices)
            ->filter(fn ($c) => ! empty($c['image_url']))
            ->unique('url')
            ->map(fn ($c) => ['name' => $c['name'], 'url' => $c['url'], 'image_url' => $c['image_url'], 'badge' => null])
            ->values();

        $rows = HomeSpotlight::query()->where('is_active', true)->orderBy('position')->get();
        $tiles = collect();

        if ($rows->isNotEmpty()) {
            $serviceIds = $rows->where('target_type', 'service')->pluck('target_id')->all();
            $categoryIds = $rows->where('target_type', 'category')->pluck('target_id')->all();

            $services = $serviceIds
                ? $this->cardsFrom($this->catalog()->services()->whereIn('id', $serviceIds), HomeSpotlight::SLOTS)
                    ->keyBy(fn ($c) => $c['service']->id)
                : collect();
            $categories = $categoryIds
                ? $this->catalog()->categories()->whereIn('id', $categoryIds)->get()->keyBy('id')
                : collect();

            foreach ($rows as $row) {
                if ($row->target_type === 'category') {
                    $category = $categories->get($row->target_id);
                    $tile = $category ? [
                        'name' => $category->name,
                        'url' => \App\Support\Seo\PublicUrl::category($category),
                        'image_url' => $category->image_url,
                    ] : null;
                } else {
                    $card = $services->get($row->target_id);
                    $tile = $card ? ['name' => $card['name'], 'url' => $card['url'], 'image_url' => $card['image_url']] : null;
                }

                if ($tile) {
                    $tiles->push($tile + ['badge' => $row->badge]);
                }
            }
        }

        if ($tiles->count() < 4) {
            $taken = $tiles->pluck('url');
            $tiles = $tiles->concat($auto->reject(fn ($t) => $taken->contains($t['url']))->take(4 - $tiles->count()));
        }

        $tiles = $tiles->take(HomeSpotlight::SLOTS)->values();
        $keep = match (true) {
            $tiles->count() >= 6 => 6,
            $tiles->count() >= 4 => 4,
            $tiles->count() >= 2 => 2,
            default => 0,
        };

        return $tiles->take($keep)->values();
    }

    /**
     * Services with a live offer for THIS viewer. The id set and the price
     * on each card both come from FlashSaleService, so the section and its
     * prices cannot disagree about what is discounted.
     *
     * @return Collection<int, array>
     */
    private function offers(): Collection
    {
        $ids = app(\App\Services\FlashSaleService::class)->activeServiceIdsFor($this->location()->viewerScope());

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->cardsFrom($this->catalog()->services()->whereIn('id', $ids), self::RAIL_LIMIT);
    }

    /**
     * "Category collections" — a handful of categories, each with a few of
     * its own services, so the page reads as a browsable marketplace rather
     * than one long undifferentiated list.
     *
     * Categories with no active services are skipped: a heading over an
     * empty row is worse than no heading. `withCount` does the filtering in
     * one query rather than by loading and discarding.
     *
     * @return Collection<int, array{category: ServiceCategory, cards: Collection<int, array>}>
     */
    private function collections(): Collection
    {
        $categories = $this->catalog()->categories()
            ->whereHas('services', fn ($q) => $q->where('is_active', true))
            ->limit(self::COLLECTION_CATEGORY_LIMIT)
            ->get();

        return $categories->map(fn (ServiceCategory $category) => [
            'category' => $category,
            'cards' => $this->cardsFrom(
                $this->catalog()->services(['category_id' => $category->id]),
                self::COLLECTION_SERVICE_LIMIT,
            ),
        ]);
    }

    /**
     * Real, active customer membership plans, or an empty collection.
     *
     * `plan_family = 'customer_membership'` and `eligible_actor_type =
     * 'customer'` are the same two columns PlanController's own customer-
     * facing listing filters on — this is a teaser for those exact rows, not
     * a second definition of what a membership is. Buying one is Phase E, so
     * the strip links there rather than implying checkout works here.
     *
     * @return Collection<int, Plan>
     */
    private function membershipPlans(): Collection
    {
        return Plan::query()
            ->where('is_active', true)
            ->where('plan_family', 'customer_membership')
            ->where('eligible_actor_type', 'customer')
            ->orderBy('price')
            ->limit(3)
            ->get();
    }

    /** Same active-only, sort_order-then-id ordering ContentController::faqs() uses. */
    private function faqs(): Collection
    {
        return Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::FAQ_LIMIT)
            ->get(['id', 'question', 'answer']);
    }
}
