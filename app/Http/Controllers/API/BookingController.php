<?php

namespace App\Http\Controllers\API;

use App\Actions\AdminCancelBookingAction;
use App\Actions\CreateBookingAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelBookingRequest;
use App\Http\Requests\Customer\StoreBookingRequest;
use App\Http\Resources\Customer\BookingResource;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Setting;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;

/**
 * P0 Customer Core API — Service booking creation/history/cancellation
 * (mission items 2/3/4). This is the resolved answer to a real conflict
 * this session found: `PropertyController`'s own docblock (and
 * `CURRENT_MASTER_CHECKPOINT.md` item 37) document, as an AUDITED fact, that
 * Service/Parcel/Taxi were deliberately built admin/call-center-creates-only
 * — "confirmed by routes/api.php having no booking-creation endpoint for any
 * of them" — unlike Property/Vehicle/Equipment/Hotel's genuine self-service
 * browse-then-book model. This P0 mission's own explicit, detailed brief
 * (Customer Core API required before Flutter client development, `POST
 * /bookings` named outright, reusing `CreateBookingAction`) is the business
 * itself directing that Service now ALSO gets a real customer self-service
 * path, ahead of the Flutter app that will call it — not a value invented
 * by this session. Logged as `KNOWN_RISKS_AND_DECISIONS.md` item 41 rather
 * than silently built or silently blocked, per mission item 12.
 *
 * Every business rule below is read from `CreateBookingAction`/
 * `AdminCancelBookingAction`/`CancellationService` as they already exist —
 * nothing here re-implements booking creation or cancellation. This
 * controller's own job is strictly the Request/DTO adaptation layer the
 * mission itself calls for: authenticate, resolve ownership, derive
 * server-side scope/pricing, then hand off to the same Action the call-
 * center admin panel already uses.
 */
class BookingController extends Controller
{
    /**
     * POST /api/bookings
     *
     * `franchise_id`/`zone_id` are derived from the customer's OWN address
     * (never accepted directly — a client could otherwise submit a
     * zone/franchise pair unrelated to where they actually are).
     * `price_quoted` is always `Service::resolvePrice()`, server-side —
     * `StoreBookingRequest` doesn't even accept a price field, so there is
     * no client input to ignore in the first place, just none to trust.
     * `customer_id` is always `$request->user()->id`.
     */
    public function store(StoreBookingRequest $request, CreateBookingAction $action)
    {
        $validated = $request->validated();
        $customer = $request->user();

        $address = Address::where('id', $validated['address_id'])->where('user_id', $customer->id)->first();
        if (! $address) {
            return ApiResponse::error('Address not found.', 404);
        }

        if (! $address->franchise_id || ! $address->zone_id) {
            return ApiResponse::error('This address is missing required location information and cannot be used for a booking.', 422);
        }

        $service = Service::where('id', $validated['service_id'])->where('is_active', true)->first();
        if (! $service) {
            return ApiResponse::error('Service not found or is no longer available.', 404);
        }

        $paymentMethod = $validated['payment_method'] ?? 'online';
        $enabledMethods = Setting::enabledPaymentMethods([
            'zone_id' => $address->zone_id,
            'franchise_id' => $address->franchise_id,
        ]);
        if (! array_key_exists($paymentMethod, $enabledMethods)) {
            return ApiResponse::error("Payment method '{$paymentMethod}' is not currently available.", 422);
        }

        try {
            $booking = $action->execute([
                'franchise_id' => $address->franchise_id,
                'zone_id' => $address->zone_id,
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'address_id' => $address->id,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'price_quoted' => $service->resolvePrice($address->franchise_id),
                'payment_method' => $paymentMethod,
                'customer_note' => $validated['customer_note'] ?? null,
            ]);
        } catch (ModuleNotActiveException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            // e.g. wallet payments disabled for this scope, or an
            // insufficient-balance WalletService rejection — a real,
            // caller-fixable conflict, not a validation or server error.
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(
            new BookingResource($booking->load(['service.category', 'service.subcategory', 'address'])),
            'Booking created.',
            201
        );
    }

    /** GET /api/bookings/mine */
    public function mine(Request $request)
    {
        $bookings = Booking::where('customer_id', $request->user()->id)
            ->with(['service.category', 'service.subcategory', 'address', 'provider.user', 'assignedWorker.user'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::paginated(BookingResource::collection($bookings));
    }

    /** GET /api/bookings/{id} — 404, not 403, on a booking that isn't the caller's own (same IDOR-safe information-hiding convention every other customer endpoint in this codebase uses). */
    public function show(Request $request, int $bookingId)
    {
        $booking = Booking::with(['service.category', 'service.subcategory', 'address', 'provider.user', 'assignedWorker.user', 'statusHistory'])
            ->find($bookingId);

        if (! $booking || $booking->customer_id !== $request->user()->id) {
            return ApiResponse::error('Booking not found.', 404);
        }

        return ApiResponse::success(new BookingResource($booking));
    }

    /**
     * POST /api/bookings/{id}/cancel — delegates entirely to
     * `AdminCancelBookingAction` (FSM guard, `CancellationService` fee/
     * refund, entitlement reversal, notification — all of it), the exact
     * same engine the admin panel's own `Bookings\Show::cancel()` calls.
     * This is a new AUTHORIZED CALLER of that one engine, not a second
     * cancellation implementation — ownership is the only thing checked
     * here that the admin caller doesn't need to (an admin's authority
     * comes from a permission grant, not owning the booking).
     */
    public function cancel(CancelBookingRequest $request, int $bookingId, AdminCancelBookingAction $action)
    {
        $booking = Booking::find($bookingId);
        if (! $booking || $booking->customer_id !== $request->user()->id) {
            return ApiResponse::error('Booking not found.', 404);
        }

        try {
            $booking = $action->execute($bookingId, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            // "Booking is already completed/cancelled" — a real state
            // conflict, exactly the case AdminCancelBookingAction's own
            // guard already refuses, not a new rule invented here.
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success(new BookingResource($booking->load(['service.category', 'service.subcategory', 'address'])), 'Booking cancelled.');
    }
}
