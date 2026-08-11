<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use App\Services\WalletTopUpService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * POST /api/wallet/topup
     * Same shape as PaymentController::createOrder() — creates a local
     * `payments` row (purpose=wallet_topup) + a Razorpay order; the
     * existing /webhooks/razorpay endpoint credits the wallet once
     * Razorpay confirms capture (see PaymentController::
     * handlePaymentCaptured()). All wallet.* limits (min/max top-up, max
     * balance, daily/monthly caps) are enforced inside
     * WalletTopUpService::requestTopUp() before any order is created.
     */
    public function topUp(Request $request, WalletTopUpService $topUpService)
    {
        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);

        $user = $request->user();
        $scope = array_filter([
            'franchise_id' => $user->franchise_id,
            'zone_id' => $user->zone_id,
        ]);

        try {
            $order = $topUpService->requestTopUp($user, (float) $validated['amount'], $scope);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order);
    }

    /** GET /api/wallet — current balance, for the future app's wallet screen. */
    public function show(Request $request, WalletService $walletService)
    {
        return response()->json(['balance' => $walletService->balance($request->user())]);
    }
}
