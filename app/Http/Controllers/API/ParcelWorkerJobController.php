<?php

namespace App\Http\Controllers\API;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Actions\RecordParcelSafetyPhotoAction;
use App\Actions\RefuseParcelAtPickupAction;
use App\Http\Controllers\Controller;
use App\Models\ParcelOrder;
use App\Services\Parcel\ParcelSettings;
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

    /**
     * POST /api/worker/parcel-orders/{parcelOrder}/safety-photos (multipart) — pickup safety: an optional photo when
     * something looks unusual. Same rules as the rider web (RecordParcelSafetyPhotoAction).
     */
    public function safetyPhoto(Request $request, int $parcelOrderId, RecordParcelSafetyPhotoAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can add parcel safety photos.'], 403);
        }

        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This parcel order is not assigned to you.'], 403);
        }

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'stage' => ['required', 'in:'.implode(',', array_keys(ParcelSettings::SAFETY_PHOTO_STAGES))],
            'concerns' => ['required', 'array', 'min:1'],
            'concerns.*' => ['in:'.implode(',', array_keys(ParcelSettings::SAFETY_CONCERNS))],
            'note' => ['nullable', 'string', 'max:300'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $photo = $action->execute($parcelOrderId, $worker, $validated['photo'], $validated['stage'], $validated['concerns'], $validated['note'] ?? null,
                isset($validated['lat']) ? (float) $validated['lat'] : null, isset($validated['lng']) ? (float) $validated['lng'] : null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Photo saved.', 'photo' => ['id' => $photo->id, 'stage' => $photo->stage, 'taken_at' => $photo->taken_at]], 201);
    }

    /** POST /api/worker/parcel-orders/{parcelOrder}/refuse — pickup safety: refuse the parcel at the pickup point. */
    public function refuse(Request $request, int $parcelOrderId, RefuseParcelAtPickupAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can refuse parcel orders.'], 403);
        }

        $order = ParcelOrder::find($parcelOrderId);
        if (! $order || $order->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This parcel order is not assigned to you.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'in:'.implode(',', array_keys(ParcelSettings::REFUSAL_REASONS))],
            'note' => ['nullable', 'string', 'max:300'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $order = $action->execute($parcelOrderId, $worker, $validated['reason'], $validated['note'] ?? null, (float) $validated['lat'], (float) $validated['lng']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Parcel refused. The order is cancelled.', 'parcel_order' => ['id' => $order->id, 'status' => $order->status]]);
    }
}
