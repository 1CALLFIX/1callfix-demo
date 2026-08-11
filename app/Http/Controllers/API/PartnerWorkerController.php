<?php

namespace App\Http\Controllers\API;

use App\Actions\AssignBookingToWorkerAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Phase B0.2 — the Partner-facing half of Service delegation. */
class PartnerWorkerController extends Controller
{
    /** POST /api/partner/workers/assign-booking */
    public function assignBooking(Request $request, AssignBookingToWorkerAction $action)
    {
        $partner = $request->user()->providerProfile;
        if (! $partner) {
            return response()->json(['message' => 'Only provider accounts can assign work to a worker.'], 403);
        }

        $validated = $request->validate([
            'booking_id' => 'required|integer',
            'field_worker_id' => 'required|integer',
        ]);

        try {
            $booking = $action->execute((int) $validated['booking_id'], $partner, (int) $validated['field_worker_id']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Booking assigned to worker.',
            'booking' => [
                'id' => $booking->id,
                'code' => $booking->code,
                'status' => $booking->status,
                'assigned_worker_id' => $booking->assigned_worker_id,
            ],
        ]);
    }
}
