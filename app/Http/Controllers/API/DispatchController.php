<?php

namespace App\Http\Controllers\API;

use App\Actions\AcceptBookingAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    /**
     * POST /api/v1/bookings/{booking}/accept
     * Called by the 1CallFix Partner app when a provider taps "Accept" on
     * an incoming job offer.
     */
    public function accept(Request $request, int $bookingId, AcceptBookingAction $action)
    {
        $provider = $request->user()->providerProfile;

        if (!$provider) {
            return response()->json(['message' => 'Only provider accounts can accept jobs.'], 403);
        }

        try {
            $booking = $action->execute($bookingId, $provider);
        } catch (\RuntimeException $e) {
            // 409 Conflict — the right status for "this offer is no longer valid",
            // as opposed to a validation error or server fault.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Job accepted.',
            'booking' => [
                'id' => $booking->id,
                'code' => $booking->code,
                'status' => $booking->status,
                'start_otp' => $booking->start_otp,
            ],
        ]);
    }
}
