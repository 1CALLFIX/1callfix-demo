<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

/**
 * Same shape as WalletController — the real customer-facing consumer for
 * points balance/redemption, not just an admin settings form. All limits
 * (minimum redemption, insufficient balance) are enforced inside
 * LoyaltyService::redeem() itself, so a direct API call can't bypass them.
 */
class LoyaltyController extends Controller
{
    /** GET /api/loyalty — current points balance. */
    public function show(Request $request, LoyaltyService $loyaltyService)
    {
        return response()->json(['points_balance' => $loyaltyService->balance($request->user())]);
    }

    /** POST /api/loyalty/redeem */
    public function redeem(Request $request, LoyaltyService $loyaltyService)
    {
        $validated = $request->validate(['points' => ['required', 'integer', 'min:1']]);

        $user = $request->user();
        $scope = array_filter([
            'franchise_id' => $user->franchise_id,
            'zone_id' => $user->zone_id,
        ]);

        try {
            $result = $loyaltyService->redeem($user, (int) $validated['points'], $scope);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
