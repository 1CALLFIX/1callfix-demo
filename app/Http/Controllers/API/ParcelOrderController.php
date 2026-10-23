<?php

namespace App\Http\Controllers\API;

use App\Actions\CreateParcelOrderAction;
use App\Actions\CustomerCancelParcelOrderAction;
use App\Exceptions\ModuleNotActiveException;
use App\Exceptions\ParcelQuoteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelParcelOrderRequest;
use App\Http\Requests\Customer\ParcelQuoteRequest;
use App\Http\Requests\Customer\StoreParcelOrderRequest;
use App\Http\Resources\Customer\ParcelOrderResource;
use App\Models\Address;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\ModuleActivationService;
use App\Services\Parcel\ParcelPaymentService;
use App\Support\Api\ApiResponse;
use App\Support\Modules;
use Illuminate\Http\Request;

/**
 * P1 Customer Parcel API. Built entirely on `CreateParcelOrderAction`/
 * `CustomerCancelParcelOrderAction`/`ParcelPaymentService` — no parcel
 * business logic duplicated here. The customer web page
 * (Customer\Parcel\Create) runs the same Actions.
 *
 * Parcel is implemented but off in every location until an admin activates
 * it on the Modules screen; `ModuleActivationService::isActive()` refuses
 * quote and create everywhere else.
 *
 * Both `pickup_address_id` AND `dropoff_address_id` must belong to the
 * authenticated customer (checked identically, 404-not-403 on mismatch) so a
 * client can never probe other users' address ids. Sending to someone else
 * (owner decision 2026-10-23: deliver to anyone) works by the client saving
 * the drop point as one of the customer's own addresses (POST /addresses)
 * and passing `recipient_name`/`recipient_phone` — the web page does exactly
 * that.
 */
class ParcelOrderController extends Controller
{
    /**
     * POST /api/parcel-orders/quote — a pure preview: the exact price and breakdown `POST /parcel-orders`
     * would charge (both run CreateParcelOrderAction::quote()), creates nothing.
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
        $moduleActive = app(ModuleActivationService::class)->isActive(Modules::PARCEL, $scope);

        try {
            $quote = $action->quote([
                'pickup_address_id' => $pickup->id,
                'dropoff_address_id' => $dropoff->id,
                'package_weight_kg' => $validated['package_weight_kg'] ?? null,
                'package_size' => $validated['package_size'] ?? 'small',
            ], $scope);
        } catch (ParcelQuoteException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success([
            'price_quoted' => $quote['total'],
            'distance_km' => $quote['distance_km'],
            'price_breakdown' => $quote['lines'],
            'currency_code' => $pickup->franchise?->country?->currency_code,
            'package_size' => $validated['package_size'] ?? 'small',
            'package_size_options' => ['small', 'medium', 'large'],
            'payment_methods' => $this->parcelPaymentMethods($pickup),
            'module_active' => $moduleActive,
            'pickup_address' => ['id' => $pickup->id, 'label' => $pickup->label, 'address_line' => $pickup->address_line],
            'dropoff_address' => ['id' => $dropoff->id, 'label' => $dropoff->label, 'address_line' => $dropoff->address_line],
        ]);
    }

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
        if (! array_key_exists($paymentMethod, $this->parcelPaymentMethods($pickup))) {
            return ApiResponse::error("Payment method '{$paymentMethod}' is not currently available.", 422);
        }

        try {
            $order = $action->execute([
                'franchise_id' => $pickup->franchise_id,
                'zone_id' => $pickup->zone_id,
                'customer_id' => $customer->id,
                'pickup_address_id' => $pickup->id,
                'dropoff_address_id' => $dropoff->id,
                'pickup_contact_name' => $validated['pickup_contact_name'] ?? null,
                'pickup_contact_phone' => $validated['pickup_contact_phone'] ?? null,
                'recipient_name' => $validated['recipient_name'] ?? null,
                'recipient_phone' => $validated['recipient_phone'] ?? null,
                'package_description' => $validated['package_description'] ?? null,
                'package_weight_kg' => $validated['package_weight_kg'] ?? null,
                'package_size' => $validated['package_size'] ?? 'small',
                'payment_method' => $paymentMethod,
                'customer_note' => $validated['customer_note'] ?? null,
                'coupon_code' => $request->input('coupon_code'),
            ]);
        } catch (ModuleNotActiveException|ParcelQuoteException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            new ParcelOrderResource($order->load(['pickupAddress', 'dropoffAddress'])),
            $order->isAwaitingOnlinePayment() ? 'Parcel order created. Complete the payment to send it to a rider.' : 'Parcel order created.',
            201
        );
    }

    /**
     * POST /api/parcel-orders/{id}/pay — opens a Razorpay order for an online parcel that is still unpaid.
     * The client opens Razorpay checkout with this payload; the capture webhook marks it paid and starts
     * the rider search.
     */
    public function pay(Request $request, int $parcelOrderId, ParcelPaymentService $payments)
    {
        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->customer_id !== $request->user()->id) {
            return ApiResponse::error('Parcel order not found.', 404);
        }

        try {
            return ApiResponse::success($payments->startOnlinePayment($order));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }
    }

    public function mine(Request $request)
    {
        $orders = ParcelOrder::where('customer_id', $request->user()->id)
            ->with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::paginated(ParcelOrderResource::collection($orders));
    }

    public function show(Request $request, int $parcelOrderId)
    {
        $order = ParcelOrder::with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user', 'statusHistory'])
            ->find($parcelOrderId);

        if (! $order || $order->customer_id !== $request->user()->id) {
            return ApiResponse::error('Parcel order not found.', 404);
        }

        return ApiResponse::success(new ParcelOrderResource($order));
    }

    public function cancel(CancelParcelOrderRequest $request, int $parcelOrderId, CustomerCancelParcelOrderAction $action)
    {
        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->customer_id !== $request->user()->id) {
            return ApiResponse::error('Parcel order not found.', 404);
        }

        try {
            $order = $action->execute($order, $request->user(), $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(new ParcelOrderResource($order->load(['pickupAddress', 'dropoffAddress'])), 'Parcel order cancelled.');
    }

    /** Payment methods a parcel can use at this pickup: the location's enabled methods, limited to online / wallet / cash. */
    private function parcelPaymentMethods(Address $pickup): array
    {
        return array_intersect_key(
            Setting::enabledPaymentMethods(['zone_id' => $pickup->zone_id, 'franchise_id' => $pickup->franchise_id]),
            array_flip(CreateParcelOrderAction::PAYMENT_METHODS)
        );
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
