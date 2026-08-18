<?php

namespace App\Http\Controllers\API;

use App\Actions\AdminCancelParcelOrderAction;
use App\Actions\CreateParcelOrderAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelParcelOrderRequest;
use App\Http\Requests\Customer\ParcelQuoteRequest;
use App\Http\Requests\Customer\StoreParcelOrderRequest;
use App\Http\Resources\Customer\ParcelOrderResource;
use App\Models\Address;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\ModuleActivationService;
use App\Support\Api\ApiResponse;
use App\Support\Modules;
use Illuminate\Http\Request;

/**
 * P1 Customer Parcel API. Built entirely on `CreateParcelOrderAction`/
 * `AdminCancelParcelOrderAction`/`CancellationService` as they already
 * exist — no parcel business logic duplicated here.
 *
 * Real, audited fact confirmed before writing this controller:
 * `modules.is_implemented` for `parcel` is `false` in every real migration
 * (`create_parcel_orders_table`'s own docblock: "Ships fully inert...
 * deliberately"). Building this API does NOT make Parcel usable in
 * production — `ModuleActivationService::isActive()` still hard-refuses
 * every request until a human flips that flag as a real, separate
 * deployment decision (Phase 22.1's own documented gate, unchanged here).
 * This mirrors exactly how Rental/Hotel/Marketplace were each built fully
 * then shipped disabled.
 *
 * Both `pickup_address_id` AND `dropoff_address_id` must belong to the
 * authenticated customer (checked identically, 404-not-403 on mismatch) —
 * a deliberate scoping decision, not an oversight: `CreateParcelOrderAction`
 * itself enforces no ownership on either address (the admin/call-center
 * caller may act for any customer), so real-world "deliver to someone
 * else's saved address" would require a genuinely new concept (a
 * non-owned recipient address, or free-text recipient details) this
 * session does not invent — logged in `KNOWN_RISKS_AND_DECISIONS.md`.
 */
class ParcelOrderController extends Controller
{
    /**
     * POST /api/parcel-orders/quote — a pure preview: computes the exact
     * same price `POST /parcel-orders` would charge (both call
     * `CreateParcelOrderAction::quote()`), but creates nothing. No quote
     * token/expiry exists (`CreateParcelOrderAction::quote()` has none to
     * preserve) — a client may call this as many times as it likes and
     * `POST /parcel-orders` always recomputes fresh, server-side, matching
     * the mission's own "do not invent a policy that doesn't exist" rule.
     * `module_active` is included so a Flutter client can grey out
     * "Confirm" without a wasted failed create call — one round trip
     * carries everything the next screen needs.
     */
    public function quote(ParcelQuoteRequest $request, CreateParcelOrderAction $action)
    {
        $validated = $request->validated();
        $customer = $request->user();

        $pickup = $this->ownedAddress($validated['pickup_address_id'], $customer->id);
        if (! $pickup) {
            return ApiResponse::error('Pickup address not found.', 404);
        }
        $dropoff = $this->ownedAddress($validated['dropoff_address_id'], $customer->id);
        if (! $dropoff) {
            return ApiResponse::error('Dropoff address not found.', 404);
        }
        if (! $pickup->franchise_id || ! $pickup->zone_id) {
            return ApiResponse::error('This pickup address is missing required location information.', 422);
        }

        $scope = $this->deriveScope($pickup);
        $price = $action->quote(['package_weight_kg' => $validated['package_weight_kg'] ?? null], $scope);

        return ApiResponse::success([
            'price_quoted' => $price,
            'currency_code' => $pickup->franchise?->country?->currency_code,
            'package_size' => $validated['package_size'] ?? 'small',
            'package_size_options' => ['small', 'medium', 'large'],
            'module_active' => app(ModuleActivationService::class)->isActive(Modules::PARCEL, $scope),
            'pickup_address' => ['id' => $pickup->id, 'label' => $pickup->label, 'address_line' => $pickup->address_line],
            'dropoff_address' => ['id' => $dropoff->id, 'label' => $dropoff->label, 'address_line' => $dropoff->address_line],
        ]);
    }

    /**
     * POST /api/parcel-orders — a client that already knows what it wants
     * (a "Book Again" repeat, or a client that skipped the quote preview)
     * can call this directly with just the two address ids and package
     * details; `CreateParcelOrderAction::execute()` computes price
     * server-side internally exactly like `quote()` above, so this is
     * never a second pricing path.
     */
    public function store(StoreParcelOrderRequest $request, CreateParcelOrderAction $action)
    {
        $validated = $request->validated();
        $customer = $request->user();

        $pickup = $this->ownedAddress($validated['pickup_address_id'], $customer->id);
        if (! $pickup) {
            return ApiResponse::error('Pickup address not found.', 404);
        }
        $dropoff = $this->ownedAddress($validated['dropoff_address_id'], $customer->id);
        if (! $dropoff) {
            return ApiResponse::error('Dropoff address not found.', 404);
        }
        if (! $pickup->franchise_id || ! $pickup->zone_id) {
            return ApiResponse::error('This pickup address is missing required location information.', 422);
        }

        $paymentMethod = $validated['payment_method'] ?? 'online';
        $enabledMethods = Setting::enabledPaymentMethods(['zone_id' => $pickup->zone_id, 'franchise_id' => $pickup->franchise_id]);
        if (! array_key_exists($paymentMethod, $enabledMethods)) {
            return ApiResponse::error("Payment method '{$paymentMethod}' is not currently available.", 422);
        }

        try {
            $order = $action->execute([
                'franchise_id' => $pickup->franchise_id,
                'zone_id' => $pickup->zone_id,
                'customer_id' => $customer->id,
                'pickup_address_id' => $pickup->id,
                'dropoff_address_id' => $dropoff->id,
                'package_description' => $validated['package_description'] ?? null,
                'package_weight_kg' => $validated['package_weight_kg'] ?? null,
                'package_size' => $validated['package_size'] ?? 'small',
                'payment_method' => $paymentMethod,
                'customer_note' => $validated['customer_note'] ?? null,
            ]);
        } catch (ModuleNotActiveException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            new ParcelOrderResource($order->load(['pickupAddress', 'dropoffAddress'])),
            'Parcel order created.',
            201
        );
    }

    /** GET /api/parcel-orders/mine */
    public function mine(Request $request)
    {
        $orders = ParcelOrder::where('customer_id', $request->user()->id)
            ->with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::paginated(ParcelOrderResource::collection($orders));
    }

    /** GET /api/parcel-orders/{id} */
    public function show(Request $request, int $parcelOrderId)
    {
        $order = ParcelOrder::with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user', 'statusHistory'])
            ->find($parcelOrderId);

        if (! $order || $order->customer_id !== $request->user()->id) {
            return ApiResponse::error('Parcel order not found.', 404);
        }

        return ApiResponse::success(new ParcelOrderResource($order));
    }

    /** POST /api/parcel-orders/{id}/cancel — delegates entirely to AdminCancelParcelOrderAction, same reuse convention BookingController::cancel() established. */
    public function cancel(CancelParcelOrderRequest $request, int $parcelOrderId, AdminCancelParcelOrderAction $action)
    {
        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->customer_id !== $request->user()->id) {
            return ApiResponse::error('Parcel order not found.', 404);
        }

        try {
            $order = $action->execute($parcelOrderId, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(new ParcelOrderResource($order->load(['pickupAddress', 'dropoffAddress'])), 'Parcel order cancelled.');
    }

    private function ownedAddress(int $addressId, int $customerId): ?Address
    {
        return Address::where('id', $addressId)->where('user_id', $customerId)->first();
    }

    private function deriveScope(Address $address): array
    {
        $address->loadMissing('franchise');

        return array_filter([
            'zone_id' => $address->zone_id,
            'franchise_id' => $address->franchise_id,
            'city_id' => $address->franchise?->city_id,
            'country_id' => $address->franchise?->country_id,
        ]);
    }
}
