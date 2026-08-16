<?php

namespace App\Http\Controllers\API;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Http\Controllers\Controller;
use App\Models\ParcelOrder;
use Illuminate\Http\Request;

/**
 * Phase 22.4 (Parcel) — the ParcelOrder counterpart to WorkerJobController.
 * Same shape exactly: profile-existence authorization (no RBAC involved,
 * matching every other provider/customer/worker-facing API in this app —
 * role_assignments is reserved for admin-panel screens), every action also
 * checks assigned_worker_id ownership so an order id alone is never
 * sufficient authorization.
 */
class ParcelWorkerJobController extends Controller
{
    /** GET /api/worker/parcel-orders — only orders actually assigned to this rider. */
    public function index(Request $request)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can view assigned parcel orders.'], 403);
        }

        $orders = ParcelOrder::where('assigned_worker_id', $worker->id)
            ->with(['pickupAddress', 'dropoffAddress'])
            ->latest()
            ->get();

        return response()->json(['parcel_orders' => $orders]);
    }

    /** POST /api/worker/parcel-orders/{parcelOrder}/accept — from a real dispatch offer. */
    public function accept(Request $request, int $parcelOrderId, AcceptParcelOfferAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can accept parcel orders.'], 403);
        }

        try {
            $order = $action->execute($parcelOrderId, $worker);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Parcel order accepted.', 'parcel_order' => ['id' => $order->id, 'status' => $order->status]]);
    }

    /** POST /api/worker/parcel-orders/{parcelOrder}/pickup */
    public function pickup(Request $request, int $parcelOrderId, MarkParcelPickedUpAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can mark parcel orders picked up.'], 403);
        }

        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This parcel order is not assigned to you.'], 403);
        }

        $validated = $request->validate(['otp' => 'required|string|size:4']);

        try {
            $order = $action->execute($parcelOrderId, $worker, $validated['otp']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Parcel picked up.', 'parcel_order' => ['id' => $order->id, 'status' => $order->status]]);
    }

    /** POST /api/worker/parcel-orders/{parcelOrder}/deliver */
    public function deliver(Request $request, int $parcelOrderId, MarkParcelDeliveredAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can mark parcel orders delivered.'], 403);
        }

        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This parcel order is not assigned to you.'], 403);
        }

        $validated = $request->validate(['otp' => 'required|string|size:4']);

        try {
            $order = $action->execute($parcelOrderId, $worker, $validated['otp']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Parcel delivered.',
            'parcel_order' => ['id' => $order->id, 'status' => $order->status, 'price_final' => $order->price_final],
        ]);
    }
}
