<?php

namespace App\Http\Controllers\API;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\BookingBundle;
use App\Models\Setting;
use App\Services\Payments\BookingBundlePaymentService;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Phase E3 (Multi-Service Booking — Payment). The customer bundle-payment
 * endpoints, the bundle counterparts of PaymentController::createOrder() /
 * confirm() for a single booking. Same lifecycle:
 *
 *   create-order  -> one pending Payment + one gateway order for the
 *                    server-authoritative aggregate amount
 *   confirm       -> provisional client-side ack (stores payment id +
 *                    signature); NOT a capture
 *   capture       -> the existing POST /api/webhooks/razorpay +
 *                    RazorpayWebhookHandler, unchanged endpoint, extended
 *                    for purpose = 'booking_bundle'
 *
 * Ownership failures return 404 (never 403) — the IDOR-safe convention every
 * other customer bundle endpoint uses (BookingBundleController::show).
 * Request amount / price / total / customer_id / franchise_id / zone_id are
 * never read.
 */
class BookingBundlePaymentController extends Controller
{
    /** POST /api/booking-bundles/{bundleId}/pay/create-order */
    public function createOrder(Request $request, int $bundleId, BookingBundlePaymentService $service)
    {
        $bundle = $this->ownedBundleOrNull($request, $bundleId);
        if (! $bundle) {
            return ApiResponse::error('Booking bundle not found.', 404);
        }

        // Same gate PaymentController::createOrder() and WalletTopUpService
        // apply — a direct API call must not bypass a disabled-online-payments
        // setting for this scope.
        $scope = array_filter(['zone_id' => $bundle->zone_id, 'franchise_id' => $bundle->franchise_id]);
        if (Setting::get('payment.online_enabled', '1', $scope) !== '1') {
            return ApiResponse::error('Online payments are currently disabled.', 422);
        }

        try {
            $result = $service->createOrder($bundle);
        } catch (\RuntimeException $e) {
            // e.g. bundle already paid — a caller-visible conflict, mapped the
            // same way BookingBundleController::store() maps its RuntimeExceptions.
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success($result, 'Bundle payment order created.');
    }

    /** POST /api/booking-bundles/{bundleId}/pay/confirm */
    public function confirm(Request $request, int $bundleId, BookingBundlePaymentService $service)
    {
        $bundle = $this->ownedBundleOrNull($request, $bundleId);
        if (! $bundle) {
            return ApiResponse::error('Booking bundle not found.', 404);
        }

        $data = $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        // Checkout-side signature check, exactly as PaymentController::confirm().
        $gateway = app(PaymentGateway::class);
        $verified = $gateway->verifyPaymentSignature(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
        );

        if (! $verified) {
            return ApiResponse::error('Payment signature verification failed.', 422);
        }

        try {
            $payment = $service->recordConfirmation($bundle, $data);
        } catch (ModelNotFoundException) {
            // A valid signature for an order that is not this bundle's pending
            // payment (wrong payment / wrong bundle) — 404, same information-
            // hiding posture as an unowned bundle.
            return ApiResponse::error('No matching pending payment for this bundle.', 404);
        }

        return ApiResponse::success(
            ['payment_id' => $payment->id, 'status' => $payment->status],
            'Payment recorded, awaiting confirmation.',
        );
    }

    /**
     * The bundle, but only if it belongs to the authenticated customer.
     * Null for both "no such bundle" and "not yours" so the two are
     * indistinguishable to a caller (IDOR-safe).
     */
    private function ownedBundleOrNull(Request $request, int $bundleId): ?BookingBundle
    {
        $bundle = BookingBundle::find($bundleId);

        if (! $bundle || $bundle->customer_id !== $request->user()->id) {
            return null;
        }

        return $bundle;
    }
}
