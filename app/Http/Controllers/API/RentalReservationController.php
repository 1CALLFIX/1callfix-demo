<?php

namespace App\Http\Controllers\API;

use App\Actions\CreateRentalReservationAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Models\RentalReservation;
use Illuminate\Http\Request;

/**
 * RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine
 * counterpart to PropertyReservationController. Same IDOR-safe ownership
 * check (customer_id must match the caller, never trusted from the id
 * alone).
 */
class RentalReservationController extends Controller
{
    /** GET /api/rental-reservations/mine */
    public function mine(Request $request)
    {
        $reservations = RentalReservation::where('customer_id', $request->user()->id)
            ->with(['rentable'])
            ->latest()
            ->get();

        return response()->json(['reservations' => $reservations]);
    }

    /** POST /api/rental-reservations */
    public function store(Request $request, CreateRentalReservationAction $action)
    {
        $validated = $request->validate([
            'rental_type' => 'required|in:vehicle,equipment',
            'rentable_id' => 'required|integer',
            'rental_mode' => 'nullable|string',
            'driver_field_worker_id' => 'nullable|integer',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'payment_method' => 'nullable|in:online,wallet,cash',
            'special_requests' => 'nullable|string',
        ]);

        try {
            $reservation = $action->execute([
                'rental_type' => $validated['rental_type'],
                'rentable_id' => $validated['rentable_id'],
                'customer_id' => $request->user()->id,
                'rental_mode' => $validated['rental_mode'] ?? null,
                'driver_field_worker_id' => $validated['driver_field_worker_id'] ?? null,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'payment_method' => $validated['payment_method'] ?? 'online',
                'special_requests' => $validated['special_requests'] ?? null,
            ]);
        } catch (ModuleNotActiveException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Reservation created.',
            'reservation' => [
                'id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status,
                'rental_type' => $reservation->rental_type, 'rental_mode' => $reservation->rental_mode,
                'price_quoted' => $reservation->price_quoted,
            ],
        ], 201);
    }

    /** GET /api/rental-reservations/{id} */
    public function show(Request $request, int $reservationId)
    {
        $reservation = RentalReservation::with(['rentable', 'statusHistory'])->find($reservationId);

        if (! $reservation || $reservation->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        return response()->json(['reservation' => $reservation]);
    }
}
