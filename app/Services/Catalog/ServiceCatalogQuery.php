<?php

namespace App\Services\Catalog;

use App\Models\Booking;
use App\Models\FranchiseServicePricing;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE catalog visibility rule for the Service vertical, in one place.
 *
 * Before this class existed the rule ("module = 'service'" + `is_active` on
 * both the category and the row itself) was written out by hand in
 * App\Http\Controllers\API\ServiceCatalogController and again in
 * App\Livewire\Customer\Home. Phase C adds five more customer screens that
 * need the identical rule, and seven hand-copied where() chains quietly
 * disagreeing is exactly the failure mode Banner::scopeForSlot()'s own
 * docblock already warns about for banner targeting. So the API controller
 * and every customer web screen now call the same builders here.
 *
 * The rule itself is UNCHANGED and is not re-derived — it is lifted verbatim
 * from ServiceCatalogController, whose own docblock documents the evidence
 * for each clause:
 *
 *  - `is_active = false` never appears. The admin screens treat that flag as
 *    the sole visibility gate; no second, stricter rule exists anywhere.
 *  - `service_categories.module` is shared across all seven verticals (an
 *    admin can file a category under any Modules::slugs() value), so every
 *    query is scoped to `service`. Without it, Marketplace/Hotel/Rental
 *    categories leak into a Service-only catalog.
 *  - Geography does NOT remove rows. Service/ServiceCategory/
 *    ServiceSubcategory carry no geography columns at all, and the one real
 *    per-franchise signal that does exist (`FranchiseServicePricing
 *    .is_offered`) is not used to hide a service even in the admin
 *    call-center booking form. Franchise context changes the PRICE
 *    (Service::resolvePrice) and which badges/flash sales apply, never the
 *    row set. Inventing a stricter geographic filter here would silently
 *    hide sellable services in the customer app that the admin panel still
 *    shows as sellable.
 *  - Module ACTIVATION is deliberately not checked, matching every other
 *    catalog-browse endpoint in this codebase. The gate is enforced once, at
 *    the real choke point, inside CreateBookingAction.
 *
 * Everything returned is a Builder, never a Collection: callers decide
 * pagination, eager loading and limits, which is what lets the same rule
 * serve a 6-tile homepage rail and a paginated category page without a
 * second query method per screen.
 */
class ServiceCatalogQuery
{
    /** Active Service-vertical categories, in the admin's own display order. */
    public function categories(): Builder
    {
        return ServiceCategory::query()
            ->where('module', Modules::SERVICE)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /** Active subcategories, optionally narrowed to one parent category. */
    public function subcategories(?int $categoryId = null): Builder
    {
        return ServiceSubcategory::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true))
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Active services whose category is an active Service-vertical category.
     *
     * Two deliberately-separate text filters, because the REST API and the
     * customer search box are answering different questions and must not be
     * silently merged:
     *
     *  - `name_like` — matches the service name only. This is exactly what
     *    `GET /api/services?search=` has always done. It is kept byte-for-byte
     *    so adopting this shared class changes no existing API behaviour.
     *  - `keyword` — the customer-facing search: name, description, and the
     *    owning category/subcategory name, because a customer types "AC" or
     *    "cleaning" (a category word) at least as often as an exact service
     *    title. See searchServices().
     *
     * @param  array{category_id?:int|null, subcategory_id?:int|null, name_like?:string|null, keyword?:string|null}  $filters
     */
    public function services(array $filters = []): Builder
    {
        $nameLike = $this->normalizeTerm($filters['name_like'] ?? null);
        $keyword = $this->normalizeTerm($filters['keyword'] ?? null);

        return Service::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true))
            ->when(($filters['category_id'] ?? null) !== null, fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when(($filters['subcategory_id'] ?? null) !== null, fn ($q) => $q->where('subcategory_id', $filters['subcategory_id']))
            // `name_like` is left UNESCAPED on purpose: that is byte-for-byte
            // what `GET /api/services?search=` has always done, and this class
            // exists to share the API's rule, not to quietly change it. The
            // customer-facing `keyword` path below does escape — see
            // applyKeyword().
            ->when($nameLike !== null, fn ($q) => $q->where('name', 'like', '%'.$nameLike.'%'))
            ->when($keyword !== null, fn ($q) => $this->applyKeyword($q, $keyword))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * The customer search box's own query — services matching a keyword
     * across name, description and their category/subcategory names,
     * optionally narrowed to one category (the category page's
     * "search within this category").
     *
     * This is a widened LIKE over columns this app already stores, not a new
     * search engine: there is no Scout/Meilisearch/full-text index anywhere
     * in this codebase (verified by inspection before writing this), and
     * adding one is a deliberate non-goal of Phase C. If search volume ever
     * justifies a real index, this one method is where it plugs in.
     *
     * @param  array{category_id?:int|null, subcategory_id?:int|null}  $filters
     */
    public function searchServices(string $term, array $filters = []): Builder
    {
        return $this->services($filters + ['keyword' => $term]);
    }

    /**
     * The customer-facing keyword match, across the service's own name and
     * description and its category/subcategory names.
     *
     * `%` and `_` are LIKE wildcards, and a customer typing "50%" means the
     * literal characters — unescaped, that term matches the entire catalogue
     * and looks like a bug. So the term is escaped AND the statement carries
     * an explicit `ESCAPE '\'`.
     *
     * The ESCAPE clause is not optional here: MySQL treats `\` as the default
     * LIKE escape character, but SQLite does NOT — without the clause, `\%`
     * on SQLite searches for a literal backslash followed by anything, and
     * "50%" silently returns nothing. This application runs SQLite in
     * dev/test and MySQL in production, so the two must not disagree. Stating
     * the escape character explicitly is valid on both and makes the
     * behaviour identical. (Caught by a test, having first shipped the
     * MySQL-only assumption.)
     */
    private function applyKeyword(Builder $query, string $term): Builder
    {
        $like = '%'.$this->escapeLike($term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('services.name like ? escape \'\\\'', [$like])
                ->orWhereRaw('services.description like ? escape \'\\\'', [$like])
                ->orWhereHas('category', fn ($c) => $c->whereRaw('service_categories.name like ? escape \'\\\'', [$like]))
                ->orWhereHas('subcategory', fn ($s) => $s->whereRaw('service_subcategories.name like ? escape \'\\\'', [$like]));
        });
    }

    /** Trims, and treats an empty/whitespace-only term as "no filter" rather than as a match-everything `%%`. */
    private function normalizeTerm(?string $term): ?string
    {
        $term = trim((string) $term);

        return $term === '' ? null : $term;
    }

    /** Categories whose own name matches, for the search screen's "categories" group. Same escaping rule as applyKeyword(). */
    public function searchCategories(string $term): Builder
    {
        return $this->categories()
            ->whereRaw('service_categories.name like ? escape \'\\\'', ['%'.$this->escapeLike(trim($term)).'%']);
    }

    /** Subcategories whose own name matches, for the search screen's "categories" group. Same escaping rule as applyKeyword(). */
    public function searchSubcategories(string $term): Builder
    {
        return $this->subcategories()
            ->whereRaw('service_subcategories.name like ? escape \'\\\'', ['%'.$this->escapeLike(trim($term)).'%']);
    }

    /**
     * Services ranked by how many real bookings they have, most first.
     *
     * This is an aggregate over the authoritative `bookings` table, NOT a
     * second popularity engine: no score is stored, nothing is written, and
     * no new table exists. That distinction matters because the Badge
     * engine's own migration docblock records that this codebase has no
     * popularity/trending STATISTICS engine — which is why its POPULAR badge
     * ships in `manual` mode. That stays true: POPULAR remains the
     * admin-curated label, and this ranking is a separate, purely-derived
     * "what did people actually book" ordering.
     *
     * Cancelled bookings are excluded — a cancelled job is not evidence that
     * a service is in demand. Everything else counts, including bookings
     * still in flight, because a booking placed today is the freshest demand
     * signal available.
     *
     * Scoped to the viewer's franchise when there is one, so a customer in
     * one city is not shown another city's ranking. With no franchise
     * context the ranking is platform-wide.
     *
     * Services with zero qualifying bookings are excluded outright (`has`,
     * not `withCount` alone). On a catalog with no booking history this
     * returns nothing, and the caller is expected to hide the section rather
     * than fall back to an arbitrary order dressed up as "most booked".
     */
    public function mostBooked(?int $franchiseId = null): Builder
    {
        $constrain = fn ($q) => $q
            ->where('status', '!=', 'cancelled')
            ->when($franchiseId !== null, fn ($b) => $b->where('franchise_id', $franchiseId));

        return $this->services()
            ->whereHas('bookings', $constrain)
            ->withCount(['bookings as bookings_count' => $constrain])
            ->reorder()
            ->orderByDesc('bookings_count')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Services newest-first — the "New & noteworthy" rail's ordering.
     *
     * Deliberately ordered by `created_at`, the same attribute the NEW
     * badge's own automatic `recently_created` rule evaluates, so the rail
     * and the badge can never tell the customer two different stories about
     * what is new. It does NOT filter to only-badged rows: the rail is "the
     * most recent additions", the badge is "recent enough to still be
     * flagged", and a catalog whose newest row is six months old should
     * still be able to show its newest rows without a green NEW pill on
     * them.
     */
    public function newest(): Builder
    {
        return $this->services()->reorder()->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Order a services query by Service::resolvePrice($franchiseId) — the
     * SQL equivalent of the STORED price cascade (franchise override ->
     * discount_price -> base_price), which is the whole quoted price only
     * for a service with no live flash sale.
     *
     * Both the customer catalog screens' "Price: low to high"/"high to low"
     * sorts go through here, so the ordering cannot drift from the STORED
     * cascade. It does not include the flash-sale layer that the card and
     * the checkout charge both do — see the deliberate exclusion below.
     *
     * The cascade being reproduced, clause for clause:
     *
     *   FranchiseServicePricing.price_override   -- only for THIS franchise,
     *                                               only where is_offered,
     *                                               only where not null
     *   else services.discount_price
     *   else services.base_price
     *
     * ── What is deliberately NOT in the ordering, and why that is correct ──
     *
     *  - Flash sale pricing. KNOWN, DOCUMENTED DIVERGENCE — read this
     *    before trusting the ordering.
     *
     *    The reason originally given here was that FlashSaleService is
     *    display-only and the booking path never consults a sale. That
     *    stopped being true in Phase D: CreateBookingAction now charges the
     *    full cascade including the sale layer. This ordering still does
     *    not, so a grid sorted by price can show a flash-sale card out of
     *    its true price position.
     *
     *    It is left out deliberately rather than by oversight. Whether a
     *    sale applies is not a property of a service row: it depends on
     *    FlashSale::isCurrentlyActive(), on AuthorizationService::
     *    scopeCovers() against the viewer's scope, and on a redemption
     *    COUNT per sale for the sold-out check — all resolved in PHP. And
     *    the discounted number itself comes from
     *    FlashSale::computeFinalPrice() (percent/flat, max_discount cap,
     *    min_final_price floor). Reproducing that in ORDER BY would mean a
     *    second implementation of the discount rules in SQL, which is
     *    exactly the drift this method was written to end. Recorded as an
     *    open gap instead.
     *  - Plan/membership entitlement adjustment. EntitlementService::
     *    resolveAndConsumeForBooking() runs inside CreateBookingAction, per
     *    customer, and CONSUMES a usage allowance as it goes. It is not a
     *    property of a service row, it differs per viewer, and evaluating it
     *    across a catalog would burn a customer's entitlements just to draw
     *    a page. It is not orderable and must not be.
     *
     * With no franchise context ($franchiseId === null) the override lookup
     * is skipped entirely — exactly as resolvePrice(null) skips it.
     */
    public function orderByEffectivePrice(Builder $query, string $direction, ?int $franchiseId): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $query->reorder();

        if ($franchiseId === null) {
            return $query
                ->orderByRaw("coalesce(services.discount_price, services.base_price) {$direction}")
                ->orderBy('services.name');
        }

        $override = FranchiseServicePricing::query()
            ->select('price_override')
            ->whereColumn('franchise_service_pricing.service_id', 'services.id')
            ->where('franchise_service_pricing.franchise_id', $franchiseId)
            ->where('franchise_service_pricing.is_offered', true)
            ->whereNotNull('franchise_service_pricing.price_override')
            ->limit(1)
            ->getQuery();

        return $query
            ->orderByRaw(
                "coalesce(({$override->toSql()}), services.discount_price, services.base_price) {$direction}",
                $override->getBindings(),
            )
            ->orderBy('services.name');
    }

    /**
     * LIKE-wildcard escaping. `\` is the default escape character on both
     * MySQL and SQLite (this app runs SQLite in dev/test, MySQL in
     * production), so the same escaping is correct on both.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Total bookings the viewer's franchise has for one service — the number
     * behind the "most booked" ranking, exposed so a detail screen can state
     * the same figure the rail ranked on.
     */
    public function bookingCountFor(int $serviceId, ?int $franchiseId = null): int
    {
        return Booking::query()
            ->where('service_id', $serviceId)
            ->where('status', '!=', 'cancelled')
            ->when($franchiseId !== null, fn ($b) => $b->where('franchise_id', $franchiseId))
            ->count();
    }
}
