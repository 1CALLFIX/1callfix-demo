<?php

namespace App\Livewire\Customer\Concerns;

use App\Models\Banner;
use App\Services\Catalog\ServiceCatalogQuery;
use App\Services\Customer\CatalogPresenter;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * The four collaborators every customer catalog screen needs, resolved the
 * same way on each (Phase C).
 *
 * Deliberately resolved per-render out of the container rather than held as
 * public Livewire properties: Livewire serialises public properties into the
 * page payload between requests, and a service object has no business making
 * that round trip. This is the same shape App\Livewire\Customer\Home already
 * used in Phase B (`app(CustomerLocationContext::class)` inside render()),
 * lifted into one place now that six screens need it.
 */
trait ResolvesCatalogContext
{
    /**
     * Re-render when the customer changes their area.
     *
     * App\Livewire\Customer\LocationPicker is a SEPARATE Livewire component
     * that writes the chosen zone to the session and then dispatches
     * `customer-zone-changed`. Without this listener the picker's own header
     * label updated but the page behind it did not: prices still showed the
     * old franchise's override, zone-targeted banners and badges did not
     * appear, and "Most booked" still ranked by the previous franchise —
     * until a manual reload. Found in browser testing, not by any unit test,
     * because each component is individually correct; it is the hand-off
     * between them that was missing.
     *
     * The body is empty on purpose: in Livewire, handling an event is itself
     * what triggers the re-render, and every screen using this trait reads
     * the zone fresh in render() anyway.
     */
    #[On('customer-zone-changed')]
    public function zoneChanged(): void
    {
        // Intentionally empty — see the docblock.
    }

    protected function catalog(): ServiceCatalogQuery
    {
        return app(ServiceCatalogQuery::class);
    }

    protected function presenter(): CatalogPresenter
    {
        return app(CatalogPresenter::class);
    }

    protected function location(): CustomerLocationContext
    {
        return app(CustomerLocationContext::class);
    }

    /**
     * Banners for one on-screen slot, already narrowed to this viewer's
     * franchise/zone and to the Service module.
     *
     * Goes through Banner::scopeForSlot() — the model's own docblock names
     * "customer app API, website home screen, admin preview" as the callers
     * that must all use it rather than hand-rolling a targeting query, and
     * this is the website home screen. Nothing about banner targeting is
     * re-implemented anywhere in the customer app.
     *
     * `$categoryId` narrows further, so a category page can carry a banner
     * sold specifically against that category.
     *
     * @return Collection<int, Banner>
     */
    protected function bannersFor(string $placement, ?int $categoryId = null): Collection
    {
        return Banner::forSlot($placement, $this->location()->bannerContext($categoryId))->get();
    }

    /**
     * Turns a query builder of services into presented cards, eager-loading
     * the relationships every card template reads so a grid never issues a
     * per-row category/subcategory lookup.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return Collection<int, array>
     */
    protected function cardsFrom($query, int $limit): Collection
    {
        return $this->presenter()->cards(
            $query->with(['category:id,name,slug', 'subcategory:id,name'])->limit($limit)->get()
        );
    }
}
