<?php

namespace App\Http\Controllers\API;

use App\Actions\CreateMarketplaceOrderAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use App\Services\Marketplace\MarketplacePaymentService;
use Illuminate\Http\Request;

/**
 * Phase 24 (Marketplace Foundation) — the real customer-facing
 * checkout/order endpoint, same shape as PropertyReservationController.
 * Ownership checked the same IDOR-safe way (customer_id must match the
 * caller; a mismatched id returns 404, not 403 -- doesn't even confirm the
 * row exists to an unauthorized caller).
 */
class MarketplaceOrderController extends Controller
{
    /** GET /api/marketplace-orders/mine */
    public function mine(Request $request)
    {
        $orders = MarketplaceOrder::where('customer_id', $request->user()->id)
            ->with(['store', 'items'])
            ->latest()
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /** POST /api/marketplace-orders (checkout) */
    public function store(Request $request, CreateMarketplaceOrderAction $action)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'order_type' => 'nullable|in:delivery,pickup',
            'delivery_address_id' => 'nullable|integer|exists:addresses,id',
            'payment_method' => 'nullable|in:online,wallet,cash',
            'special_instructions' => 'nullable|string',
        ]);

        try {
            $order = $action->execute([
                'store_id' => $validated['store_id'],
                'customer_id' => $request->user()->id,
                'order_type' => $validated['order_type'] ?? 'delivery',
                'delivery_address_id' => $validated['delivery_address_id'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'online',
                'special_instructions' => $validated['special_instructions'] ?? null,
            ]);
        } catch (ModuleNotActiveException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => $order->isAwaitingOnlinePayment() ? 'Order created. Complete the payment to send it to the shop.' : 'Order placed.',
            'order' => [
                'id' => $order->id, 'code' => $order->code, 'status' => $order->status,
                'total_amount' => $order->total_amount, 'discount_amount' => $order->discount_amount,
                'payment_status' => $order->payment_status, 'awaiting_payment' => $order->isAwaitingOnlinePayment(),
            ],
        ], 201);
    }

    /**
     * POST /api/marketplace-orders/{id}/pay — E-commerce Phase 0: opens a Razorpay order for an online shop
     * order that is still unpaid. The capture webhook marks it paid; only then can the shop accept it.
     */
    public function pay(Request $request, int $orderId, MarketplacePaymentService $payments)
    {
        $order = MarketplaceOrder::find($orderId);
        if (! $order || $order->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        try {
            return response()->json($payments->startOnlinePayment($order));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /** GET /api/marketplace-orders/{id} */
    public function show(Request $request, int $orderId)
    {
        $order = MarketplaceOrder::with(['store', 'items', 'statusHistory'])->find($orderId);

        if (! $order || $order->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['order' => $order]);
    }
}
