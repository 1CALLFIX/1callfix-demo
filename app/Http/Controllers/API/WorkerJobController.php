<?php

namespace App\Http\Controllers\API;

use App\Actions\CompleteBookingAction;
use App\Actions\StartBookingAction;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

/**
 * Phase B0.2 — the Worker-facing half of Service delegation. Mirrors
 * DispatchController's exact shape (profile-existence authorization, no
 * RBAC/hasPermission() involved — the same convention every provider/
 * customer-facing API endpoint in this app already uses; role_assignments
 * is reserved for admin-panel screens), but resolves fieldWorkerProfile()
 * instead of providerProfile(), and every action additionally checks
 * assigned_worker_id ownership — a booking id alone is never sufficient
 * authorization.
 */
class WorkerJobController extends Controller
{
    /** GET /api/worker/jobs — only bookings actually assigned to this worker. */
    public function index(Request $request)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can view assigned jobs.'], 403);
        }

        $bookings = Booking::where('assigned_worker_id', $worker->id)
            ->with(['service', 'address'])
            ->latest()
            ->get();

        return response()->json(['bookings' => $bookings]);
    }

    /** POST /api/worker/jobs/{booking}/start */
    public function start(Request $request, int $bookingId, StartBookingAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can start jobs.'], 403);
        }

        $booking = Booking::find($bookingId);
        if (! $booking || $booking->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This job is not assigned to you.'], 403);
        }

        $validated = $request->validate(['otp' => 'required|string|size:4']);

        try {
            $booking = $action->execute($bookingId, $validated['otp'], $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Job started.', 'booking' => ['id' => $booking->id, 'status' => $booking->status]]);
    }

    /** POST /api/worker/jobs/{booking}/complete */
    public function complete(Request $request, int $bookingId, CompleteBookingAction $action)
    {
        $worker = $request->user()->fieldWorkerProfile;
        if (! $worker) {
            return response()->json(['message' => 'Only field worker accounts can complete jobs.'], 403);
        }

        $booking = Booking::find($bookingId);
        if (! $booking || $booking->assigned_worker_id !== $worker->id) {
            return response()->json(['message' => 'This job is not assigned to you.'], 403);
        }

        $validated = $request->validate(['otp' => 'required|string|size:4']);

        // Completion is recorded against the booking's own accountable
        // Provider — commission/wallet crediting is untouched, exactly as
        // required. The Worker's authorization to trigger this is fully
        // checked above; CompleteBookingAction itself is not modified.
        try {
            $booking = $action->execute($bookingId, $booking->provider, $validated['otp']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Job completed.',
            'booking' => ['id' => $booking->id, 'status' => $booking->status, 'price_final' => $booking->price_final],
        ]);
    }
}
