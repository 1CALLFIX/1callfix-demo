<?php

namespace App\Services\Customer;

use App\Models\Service;
use App\Models\Setting;
use App\Services\BadgeService;
use App\Services\FlashSaleService;
use Illuminate\Support\Collection;

/**
 * Turns raw `Service` rows into the view model every customer-facing service
 * card and detail page renders (Phase C).
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 * A service card shows six things that each come from a different backend
 * subsystem: the price cascade (Service::resolvePrice + FlashSaleService),
 * the Badge engine, the reviews aggregate, the franchise context, the
 * currency setting, and the service row itself. Assembling those inline in
 * each Blade template would mean six chances per screen to call one of them
 * slightly differently — and it would put price arithmetic in a template.
 * One presenter means the homepage rails, the category grid, the search
 * results and the detail page cannot disagree about what a service costs or
 * which badges it carries.
 *
 * ── Pricing is never computed here ────────────────────────────────────────
 * This class does no pricing arithmetic of its own beyond a display
 * percentage. The number a customer sees is whatever the EXISTING cascade
 * returns, via the one call that owns it end to end:
 *
 *     FlashSaleService::effectivePricesFor($services, $franchiseId, $scope)
 *         = Service::resolvePrice($franchiseId)   franchise override -> discount_price -> base_price
 *           then the flash-sale layer             an active, scope-covering, not-sold-out sale wins outright
 *
 * Still display-only — a booking's charge is recomputed server-side inside
 * CreateBookingAction and never taken from anything shown here. What
 * changed in Phase D is that the recomputation now goes through this exact
 * same method, so "recomputed independently" no longer means "can produce a
 * different number". Before, this class applied the sale layer and the
 * booking path did not.
 *
 * ── Everything is batched ─────────────────────────────────────────────────
 * cards() answers a whole grid in a fixed number of queries no matter how
 * many services are passed. Callers must pass the full collection rather
 * than looping — see BadgeService::badgesForMany() and
 * FlashSaleService::priceForMany().
 *
 * ── Missing data is omitted, never faked ──────────────────────────────────
 * A service with no reviews has `rating => null` (not 0.0), a service with
 * no image has `image_url => null`, and a price with no saving has
 * `original_price => null`. Templates hide those elements. An unrated
 * service and a one-star service must never look the same.
 */
class CatalogPresenter
{
    public function __construct(
        private CustomerLocationContext $location,
        private BadgeService $badges,
        private FlashSaleService $flashSales,
        private ServiceRatingSummary $ratings,
    ) {
    }

    /** The admin-configurable symbol every other price in this application already renders through. */
    public function currencySymbol(): string
    {
        return Setting::get('locale.currency_symbol', '₹');
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return Collection<int, array> one card view model per service, in the order given
     */
    public function cards(Collection $services): Collection
    {
        if ($services->isEmpty()) {
            return collect();
        }

        $franchiseId = $this->location->franchiseId();
        $viewerScope = $this->location->viewerScope();

        // The whole cascade, batched, in ONE call — the same
        // FlashSaleService::effectivePricesFor() that
        // CreateBookingAction::resolveAuthoritativePrice() charges from, so
        // the price on a card and the price at checkout are not merely
        // computed the same way, they are computed by the same code
        // (Phase D). This used to compose the two layers here by hand,
        // which is precisely how the booking path came to apply only the
        // first of them.
        $effective = $this->flashSales->effectivePricesFor($services, $franchiseId, $viewerScope);

        $badges = $this->badges->badgesForMany($services, $viewerScope);
        $ratings = $this->ratings->forServices($services->pluck('id'));

        return $services->map(fn (Service $service) => $this->card(
            $service,
            $effective[$service->id]['resolved_price'],
            $effective[$service->id]['sale'],
            $badges[$service->id] ?? [],
            $ratings->get($service->id),
        ))->values();
    }

    /** One card. Prefer cards() — this exists for the detail page, which genuinely has a single service. */
    public function card(
        Service $service,
        ?float $resolvedPrice = null,
        ?array $flashSale = null,
        ?array $badges = null,
        ?array $rating = null,
    ): array {
        if ($resolvedPrice === null) {
            return $this->cards(collect([$service]))->first();
        }

        $finalPrice = $flashSale ? (float) $flashSale['final_price'] : $resolvedPrice;
        $listPrice = (float) $service->base_price;

        // A strike-through price is only shown when there is a real saving
        // against the service's own list price. A franchise override that
        // happens to be HIGHER than base_price is a legitimate local price,
        // not a discount, and must not be dressed up as one.
        $hasSaving = $listPrice > $finalPrice;

        return [
            'service' => $service,
            'name' => $service->name,
            'description' => $service->description,
            'category' => $service->category,
            'subcategory' => $service->subcategory,
            'image_url' => $service->cover_image_url,
            'url' => route('customer.services.show', $service),

            'price' => $finalPrice,
            'original_price' => $hasSaving ? $listPrice : null,
            'discount_percent' => $hasSaving ? (int) round((($listPrice - $finalPrice) / $listPrice) * 100) : null,
            // `quote_on_inspection` renders as "Starts from" — Service::
            // PRICE_TYPE_LABELS' own wording, because the price shown is
            // explicitly not final for that type.
            'price_prefix' => $service->price_type === 'quote_on_inspection' ? 'Starts from' : 'From',
            'price_type' => $service->price_type,
            'flash_sale' => $flashSale,

            'duration_mins' => $service->duration_estimate_mins ?: null,
            'badges' => $badges ?? [],
            'rating' => $rating['average'] ?? null,
            'review_count' => $rating['count'] ?? null,
        ];
    }
}
