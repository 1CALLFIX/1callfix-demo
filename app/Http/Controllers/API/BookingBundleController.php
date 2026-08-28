<?php

namespace App\Http\Controllers\API;

use App\Actions\CreateBookingBundleAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreBookingBundleRequest;
use App\Http\Resources\Customer\BookingBundleResource;
use App\Models\Address;
use App\Models\BookingBundle;
use App\Models\Service;
use App\Models\Setting;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;

/**
 * Phase E2 (Multi-Service Booking — Creation). The customer bundle endpoint.
 *
 * Same Request/DTO-adaptation job BookingController does for the single
 * booking: authenticate, resolve per-service address ownership, derive
 * server-side franchise/zone/pricing context, then hand a fully-trusted
 * array to the one Action (CreateBookingBundleAction) that reuses the
 * existing booking + pricing + wallet + dispatch engines. Nothing here
 * re-implements booking creation or pricing.
 *
 * Every guard below is the same one BookingController::store() applies to a
 * single service, run once per service in the bundle:
 *   - address belongs to the authenticated customer  → 404
 *   - address has a usable franchise/zone            → 422
 *   - service exists and is active                   → 404
 *   - payment method enabled for that scope          → 422
 * franchise_id / zone_id / customer_id / price are never read from the
 * request.
 */
class BookingBundleController extends Controller
{
    /** POST /api/booking-bundles */
    public function store(StoreBookingBundleRequest $request, CreateBookingBundleAction $action)
    {
        $validated = $request->validated();
        $customer = $request->user();
        $paymentMethod = $validated['payment_method'] ?? 'online';

        // Idempotency key: standard header first, request-body field as a
        // fallback for clients that can't set headers.
        $idempotencyKey = $request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? null);
        $idempotencyKey = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null;

        $children = [];
        foreach ($validated['services'] as $row) {
            $address = Address::where('id', $row['address_id'])->where('user_id', $customer->id)->first();
            if (! $address) {
                // IDOR-safe: a customer probing another customer's address id
                // gets the same "not found" every other customer endpoint gives.
                return ApiResponse::error('Address not found.', 404);
            }

            if (! $address->franchise_id || ! $address->zone_id) {
                return ApiResponse::error('This address is missing required location information and cannot be used for a booking.', 422);
            }

            $service = Service::where('id', $row['service_id'])->where('is_active', true)->first();
            if (! $service) {
                return ApiResponse::error('Service not found or is no longer available.', 404);
            }

            $enabledMethods = Setting::enabledPaymentMethods([
                'zone_id' => $address->zone_id,
                'franchise_id' => $address->franchise_id,
            ]);
            if (! array_key_exists($paymentMethod, $enabledMethods)) {
                return ApiResponse::error("Payment method '{$paymentMethod}' is not currently available.", 422);
            }

            $children[] = [
                'service_id' => $service->id,
                'franchise_id' => $address->franchise_id,
                'zone_id' => $address->zone_id,
                'address_id' => $address->id,
                'scheduled_at' => $row['scheduled_at'] ?? null,
                'customer_note' => $row['customer_note'] ?? null,
            ];
        }

        try {
            $bundle = $action->execute([
                'customer_id' => $customer->id,
                'payment_method' => $paymentMethod,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $this->fingerprint($children, $paymentMethod),
                'children' => $children,
            ]);
        } catch (ModuleNotActiveException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            // Idempotency-key-reused-with-different-body, wallet payments
            // disabled for the scope, an insufficient-balance WalletService
            // rejection, a sold-out flash sale — all caller-fixable
            // conflicts, exactly as BookingController::store() maps them.
            return ApiResponse::error($e->getMessage(), 409);
        }

        // A replayed idempotent request returns the original bundle with 200;
        // a genuinely new bundle returns 201.
        $status = $bundle->wasRecentlyCreated ? 201 : 200;

        return ApiResponse::success(
            new BookingBundleResource($bundle),
            $bundle->wasRecentlyCreated ? 'Bundle created.' : 'Bundle already created.',
            $status
        );
    }

    /**
     * GET /api/booking-bundles/{bundleId} — 404 (not 403) on a bundle that
     * isn't the caller's own, the same IDOR-safe information-hiding
     * convention BookingController::show() and every other customer endpoint
     * in this codebase uses.
     */
    public function show(Request $request, int $bundleId)
    {
        $bundle = BookingBundle::with([
            'children.service.category',
            'children.service.subcategory',
            'children.address',
        ])->find($bundleId);

        if (! $bundle || $bundle->customer_id !== $request->user()->id) {
            return ApiResponse::error('Booking bundle not found.', 404);
        }

        return ApiResponse::success(new BookingBundleResource($bundle));
    }

    /**
     * A stable hash of the parts of the request that define "the same
     * bundle": the ordered list of (service, address, schedule, note) plus
     * the payment method. A retry with an identical body hashes the same and
     * replays the original bundle; a retry that adds/removes/changes a
     * service (or changes the payment method) hashes differently and is
     * rejected rather than silently mutating the first bundle.
     */
    private function fingerprint(array $children, string $paymentMethod): string
    {
        $normalised = array_map(fn ($c) => [
            'service_id' => (int) $c['service_id'],
            'address_id' => (int) $c['address_id'],
            'scheduled_at' => $c['scheduled_at'] ?? null,
            'customer_note' => $c['customer_note'] ?? null,
        ], $children);

        return hash('sha256', json_encode([$paymentMethod, $normalised]));
    }
}
