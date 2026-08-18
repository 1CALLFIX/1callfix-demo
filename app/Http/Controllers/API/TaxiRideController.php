<?php

namespace App\Http\Controllers\API;

use App\Actions\AdminCancelTaxiRideAction;
use App\Actions\CreateTaxiRideAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelTaxiRideRequest;
use App\Http\Requests\Customer\StoreTaxiRideRequest;
use App\Http\Requests\Customer\TaxiQuoteRequest;
use App\Http\Resources\Customer\TaxiRideResource;
use App\Models\Address;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Services\ModuleActivationService;
use App\Support\Api\ApiResponse;
use App\Support\Modules;
use Illuminate\Http\Request;

/**
 * P1 Customer Taxi API. Built entirely on `CreateTaxiRideAction`/
 * `AdminCancelTaxiRideAction`/`CancellationService`/the existing
 * `TaxiDispatchJob` — no ride business logic duplicated here, no second
 * dispatch engine. `modules.is_implemented` for `taxi` is `false` in every
 * real migration, same "ships fully inert" gate `ParcelOrderController`'s
 * own docblock explains — unchanged by this build.
 *
 * No vehicle-category concept exists anywhere in this codebase
 * (`CreateTaxiRideAction`/`taxi_rides` migration both confirmed to have
 * none) — no `GET /taxi-categories` endpoint is built; inventing one would
 * violate the mission's own "only expose capabilities genuinely
 * supported" rule. Fare is a single flat `taxi.base_fare` Setting; a real
 * distance/time/surge model is a documented open business decision
 * (`KNOWN_RISKS_AND_DECISIONS.md` item 32), not guessed here. `driver_id`
 * is never an accepted field anywhere in this controller or
 * `StoreTaxiRideRequest` — assignment is entirely `TaxiDispatchJob`'s job.
 */
class TaxiRideController extends Controller
{
    /** POST /api/taxi-rides/quote — same preview-only reasoning as ParcelOrderController::quote(). */
    public function quote(TaxiQuoteRequest $request, CreateTaxiRideAction $action)
    {
        $validated = $request->validated();
        $customer = $request->user();

        $pickup = $this->ownedAddress($validated['pickup_address_id'], $customer->id);
        if (! $pickup) {
            return ApiResponse::error('Pickup address not found.', 404);
        }
        $dropoff = null;
        if (! empty($validated['dropoff_address_id'])) {
            $dropoff = $this->ownedAddress($validated['dropoff_address_id'], $customer->id);
            if (! $dropoff) {
                return ApiResponse::error('Dropoff address not found.', 404);
            }
        }
        if (! $pickup->franchise_id || ! $pickup->zone_id) {
            return ApiResponse::error('This pickup address is missing required location information.', 422);
        }

        $scope = $this->deriveScope($pickup);
        $price = $action->quote([], $scope);

        return ApiResponse::success([
            'price_quoted' => $price,
            'currency_code' => $pickup->franchise?->country?->currency_code,
            'module_active' => app(ModuleActivationService::class)->isActive(Modules::TAXI, $scope),
            'pickup_address' => ['id' => $pickup->id, 'label' => $pickup->label, 'address_line' => $pickup->address_line],
            'dropoff_address' => $dropoff ? ['id' => $dropoff->id, 'label' => $dropoff->label, 'address_line' => $dropoff->address_line] : null,
        ]);
    }

    /** POST /api/taxi-rides */
    public function store(StoreTaxiRideRequest $request, CreateTaxiRideAction $action)
    {
        $validated = $request->validated();
        $customer = $request->user();

        $pickup = $this->ownedAddress($validated['pickup_address_id'], $customer->id);
        if (! $pickup) {
            return ApiResponse::error('Pickup address not found.', 404);
        }
        $dropoff = null;
        if (! empty($validated['dropoff_address_id'])) {
            $dropoff = $this->ownedAddress($validated['dropoff_address_id'], $customer->id);
            if (! $dropoff) {
                return ApiResponse::error('Dropoff address not found.', 404);
            }
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
            $ride = $action->execute([
                'franchise_id' => $pickup->franchise_id,
                'zone_id' => $pickup->zone_id,
                'customer_id' => $customer->id,
                'pickup_address_id' => $pickup->id,
                'dropoff_address_id' => $dropoff?->id,
                'payment_method' => $paymentMethod,
                'customer_note' => $validated['customer_note'] ?? null,
            ]);
        } catch (ModuleNotActiveException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            new TaxiRideResource($ride->load(['pickupAddress', 'dropoffAddress'])),
            'Ride requested.',
            201
        );
    }

    /** GET /api/taxi-rides/mine */
    public function mine(Request $request)
    {
        $rides = TaxiRide::where('customer_id', $request->user()->id)
            ->with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::paginated(TaxiRideResource::collection($rides));
    }

    /** GET /api/taxi-rides/{id} */
    public function show(Request $request, int $taxiRideId)
    {
        $ride = TaxiRide::with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user', 'statusHistory'])
            ->find($taxiRideId);

        if (! $ride || $ride->customer_id !== $request->user()->id) {
            return ApiResponse::error('Ride not found.', 404);
        }

        return ApiResponse::success(new TaxiRideResource($ride));
    }

    /** POST /api/taxi-rides/{id}/cancel — delegates entirely to AdminCancelTaxiRideAction. */
    public function cancel(CancelTaxiRideRequest $request, int $taxiRideId, AdminCancelTaxiRideAction $action)
    {
        $ride = TaxiRide::find($taxiRideId);
        if (! $ride || $ride->customer_id !== $request->user()->id) {
            return ApiResponse::error('Ride not found.', 404);
        }

        try {
            $ride = $action->execute($taxiRideId, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(new TaxiRideResource($ride->load(['pickupAddress', 'dropoffAddress'])), 'Ride cancelled.');
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
