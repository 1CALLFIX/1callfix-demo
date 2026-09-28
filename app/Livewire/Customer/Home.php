<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\Faq;
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

    private const CATEGORY_SHORTCUT_LIMIT = 8;
    private const RAIL_LIMIT = 8;
    private const COLLECTION_CATEGORY_LIMIT = 4;
    private const COLLECTION_SERVICE_LIMIT = 4;
    private const FAQ_LIMIT = 6;

    public function render()
    {
        $location = $this->location();
        $catalog = $this->catalog();

        return view('livewire.customer.home', [
            'activeZone' => $location->zone(),
            'heroBanners' => $this->bannersFor('top'),
            'midBanners' => $this->bannersFor('mid'),
            'categories' => $catalog->categories()->limit(self::CATEGORY_SHORTCUT_LIMIT)->get(),
            'newServices' => $this->cardsFrom($catalog->newest(), self::RAIL_LIMIT),
            'mostBooked' => $this->cardsFrom($catalog->mostBooked($location->franchiseId()), self::RAIL_LIMIT),
            'offers' => $this->offers(),
            'collections' => $this->collections(),
            'membershipPlans' => $this->membershipPlans(),
            'faqs' => $this->faqs(),
            'currencySymbol' => $this->presenter()->currencySymbol(),
        ])->layout('components.layouts.customer', [
            'title' => 'Home services, on call',
        ]);
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
