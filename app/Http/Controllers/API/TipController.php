<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\TipService;
use Illuminate\Http\Request;

/**
 * Customer-initiated tips (Phase 11 audit finding: TipService has existed
 * since mission Phase 5 — full ownership/status/idempotency guards, real
 * wallet-to-wallet money movement — with zero callers anywhere in app/ or
 * routes/, tested only via direct service instantiation. Same convention as
 * ChatController: one small controller per real booking-scoped action, the
 * service does all the actual authorization/validation and throws on
 * anything invalid, which surfaces here as a 4xx rather than a leaked 500.
 */
class TipController extends Controller
{
    /** POST /api/bookings/{bookingId}/tip */
    public function store(Request $request, int $bookingId, TipService $tips)
    {
        $booking = Booking::find($bookingId);
        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $compensation = $tips->addTip($booking, $request->user(), (float) $validated['amount']);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Tip added.',
            'tip' => ['id' => $compensation->id, 'amount' => (float) $compensation->amount],
        ]);
    }
}
