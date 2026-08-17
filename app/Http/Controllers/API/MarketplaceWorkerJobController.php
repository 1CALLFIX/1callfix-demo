<?php

namespace App\Http\Controllers\API;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use Illuminate\Http\Request;

/**
 * Phase 24 (Marketplace Foundation) — the MarketplaceOrder counterpart to
 * ParcelWorkerJobController. Same shape: profile-existence authorization
 * (no RBAC), every action also checks assigned_worker_id ownership so an
 * order id alone is never sufficient.
 */
class MarketplaceWorkerJobController extends Controller
{
    /** GET /api/worker/marketplace-orders — only orders actually assigned to this rider. */
    public function index(Request $request)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can view assigned marketplace orders.'], 403);
        }

        $orders = MarketplaceOrder::where('assigned_worker_id', $worker->id)
            ->with(['store', 'deliveryAddress', 'items'])
            ->latest()
            ->get();

        return response()->json(['marketplace_orders' => $orders]);
    }

    /** POST /api/worker/marketplace-orders/{marketplaceOrder}/accept — from a real dispatch offer. */
    public function accept(Request $request, int $marketplaceOrderId, AcceptMarketplaceDeliveryOfferAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can accept marketplace orders.'], 403);
        }

        try {
            $order = $action->execute($marketplaceOrderId, $worker);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Order accepted.', 'marketplace_order' => ['id' => $order->id, 'status' => $order->status]]);
    }

    /** POST /api/worker/marketplace-orders/{marketplaceOrder}/deliver */
    public function deliver(Request $request, int $marketplaceOrderId, CompleteMarketplaceOrderAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can mark marketplace orders delivered.'], 403);
        }

        $order = MarketplaceOrder::find($marketplaceOrderId);
        if (! $order || $order->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }

        $validated = $request->validate(['otp' => 'required|string|size:4']);

        try {
            $order = $action->execute($marketplaceOrderId, $worker, $validated['otp']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Order delivered.', 'marketplace_order' => ['id' => $order->id, 'status' => $order->status]]);
    }
}
