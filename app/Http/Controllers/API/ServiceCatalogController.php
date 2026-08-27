<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ServiceCategoryResource;
use App\Http\Resources\Customer\ServiceResource;
use App\Http\Resources\Customer\ServiceSubcategoryResource;
use App\Models\Franchise;
use App\Services\Catalog\ServiceCatalogQuery;
use App\Services\FlashSaleService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;

/**
 * P0 Customer Core API — Service catalog discovery (mission item 1). Public,
 * unauthenticated, same reasoning as every other browse-first endpoint in
 * this app (Property/Vehicle/Equipment/Hotel/Store — see PropertyController's
 * own docblock): a customer browses the catalog before deciding to book.
 *
 * Filtering rules, all evidence-based, none invented:
 *  - `is_active = false` rows never appear (categories/subcategories/
 *    services all carry this flag already; the admin screens already treat
 *    it as the sole visibility gate — no second, stricter rule exists).
 *  - `service_categories.module` is a genuinely shared column across every
 *    vertical (`Livewire\Categories\Manage` lets an admin file a category
 *    under ANY `Modules::slugs()` value, not just `service` — confirmed by
 *    direct inspection before writing this filter) — so every query here
 *    is scoped to `module = 'service'` specifically. Without this, browsing
 *    `/api/categories` would leak Parcel/Taxi/Rental/... category rows into
 *    a Service-only catalog endpoint.
 *  - `franchise_id`/`zone_id` are NOT used to exclude services from the
 *    list: `Service`/`ServiceCategory`/`ServiceSubcategory` carry no
 *    geography columns at all, and the one real per-franchise signal that
 *    exists (`FranchiseServicePricing.is_offered`) is NOT currently used to
 *    hide a service even in the admin call-center booking form itself
 *    (`Livewire\Bookings\Index`'s own service dropdown is
 *    `Service::where('is_active', true)`, unfiltered by `is_offered`) — so
 *    this matches that real, established behavior rather than inventing a
 *    stricter one. An optional `?franchise_id=` on `GET /services` only
 *    affects the returned `effective_price` (see `ServiceResource`), never
 *    which rows are returned. Note that a ZONE-scoped flash sale cannot
 *    apply to this preview: the caller has told us a franchise, not a zone,
 *    so only franchise/city/country-scoped sales are in view here. A booking
 *    resolves the sale against the customer's real address, so a zone-scoped
 *    sale still applies there — the preview under-promises rather than
 *    over-promising, which is the safe direction.
 *  - Module ACTIVATION (`ModuleActivationService`) is deliberately NOT
 *    checked here, matching every other catalog-browse endpoint in this
 *    codebase (`PropertyController`/`VehicleController`/`HotelController`/
 *    `StoreController` none of them gate browsing on module activation —
 *    only the actual order/reservation-creation Action does). The gate is
 *    enforced once, at the real choke point, inside `CreateBookingAction`
 *    (via `BookingController::store()`), not duplicated here.
 */
class ServiceCatalogController extends Controller
{
    /**
     * Phase C: the where() chains these three methods used to spell out by
     * hand now live in App\Services\Catalog\ServiceCatalogQuery, shared with
     * every customer web screen so the two can never drift into different
     * answers about what is visible. The RULE is unchanged — including
     * `?search=` still matching the service name only (`name_like`), not the
     * wider match the customer search box uses. See that class's docblock.
     */
    public function __construct(
        private ServiceCatalogQuery $catalog,
        private FlashSaleService $flashSales,
    ) {
    }

    /** GET /api/categories */
    public function categories(Request $request)
    {
        return ApiResponse::success(ServiceCategoryResource::collection($this->catalog->categories()->get()));
    }

    /** GET /api/subcategories?category_id= */
    public function subcategories(Request $request)
    {
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;

        return ApiResponse::success(ServiceSubcategoryResource::collection($this->catalog->subcategories($categoryId)->get()));
    }

    /** GET /api/services?category_id=&subcategory_id=&franchise_id=&search= */
    public function services(Request $request)
    {
        $services = $this->catalog->services([
            'category_id' => $request->filled('category_id') ? $request->integer('category_id') : null,
            'subcategory_id' => $request->filled('subcategory_id') ? $request->integer('subcategory_id') : null,
            'name_like' => $request->filled('search') ? (string) $request->string('search') : null,
        ])->limit(100)->get();

        // Phase D: `effective_price` is resolved through the SAME
        // FlashSaleService::effectivePricesFor() the booking path charges
        // from, batched for the whole page (one pass, not one lookup per
        // row). Before this, the preview showed only the first layer of the
        // cascade while a flash sale really was in force, so a browsing
        // customer saw a higher number than a booking would be charged.
        $franchise = $request->filled('franchise_id')
            ? Franchise::find($request->integer('franchise_id'))
            : null;

        $prices = $this->flashSales->effectivePricesFor($services, $franchise?->id, array_filter([
            'franchise_id' => $franchise?->id,
            'city_id' => $franchise?->city_id,
            'country_id' => $franchise?->country_id,
        ], fn ($value) => $value !== null));

        $services->each(fn ($service) => $service->setEffectivePrice($prices[$service->id]['price']));

        return ApiResponse::success(ServiceResource::collection($services));
    }
}
