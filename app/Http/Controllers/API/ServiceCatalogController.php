<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ServiceCategoryResource;
use App\Http\Resources\Customer\ServiceResource;
use App\Http\Resources\Customer\ServiceSubcategoryResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Support\Api\ApiResponse;
use App\Support\Modules;
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
 *    which rows are returned.
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
    /** GET /api/categories */
    public function categories(Request $request)
    {
        $categories = ServiceCategory::where('module', Modules::SERVICE)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ServiceCategoryResource::collection($categories));
    }

    /** GET /api/subcategories?category_id= */
    public function subcategories(Request $request)
    {
        $query = ServiceSubcategory::where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true));

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $subcategories = $query->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(ServiceSubcategoryResource::collection($subcategories));
    }

    /** GET /api/services?category_id=&subcategory_id=&franchise_id=&search= */
    public function services(Request $request)
    {
        $query = Service::where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true));

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', $request->integer('subcategory_id'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        $services = $query->orderBy('sort_order')->orderBy('name')->limit(100)->get();

        return ApiResponse::success(ServiceResource::collection($services));
    }
}
