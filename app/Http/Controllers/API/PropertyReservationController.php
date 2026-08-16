<?php

namespace App\Http\Controllers\API;

use App\Actions\CreatePropertyReservationAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyReservation;
use Illuminate\Http\Request;

/**
 * Phase 22.7 (Property Rental) — the real customer-facing reservation
 * endpoint this vertical's own genuine self-service model justifies (see
 * PropertyController's own docblock). Ownership is checked the same
 * IDOR-safe way every other customer-facing API endpoint in this app
 * already does (booking/wallet/loyalty controllers) — a reservation id
 * alone is never sufficient, `customer_id` must match the caller.
 */
class PropertyReservationController extends Controller
{
    /** GET /api/reservations/mine */
    public function mine(Request $request)
    {
        $reservations = PropertyReservation::where('customer_id', $request->user()->id)
            ->with(['property'])
            ->latest()
            ->get();

        return response()->json(['reservations' => $reservations]);
    }

    /** POST /api/properties/{id}/reservations */
    public function store(Request $request, int $propertyId, CreatePropertyReservationAction $action)
    {
        $property = Property::find($propertyId);
        if (! $property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $validated = $request->validate([
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'number_of_guests' => 'nullable|integer|min:1',
            'payment_method' => 'nullable|in:online,wallet,cash',
            'special_requests' => 'nullable|string',
        ]);

        try {
            $reservation = $action->execute([
                'franchise_id' => $property->franchise_id,
                'zone_id' => $property->zone_id,
                'customer_id' => $request->user()->id,
                'property_id' => $property->id,
                'check_in_date' => $validated['check_in_date'],
                'check_out_date' => $validated['check_out_date'],
                'number_of_guests' => $validated['number_of_guests'] ?? 1,
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
            'reservation' => ['id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status, 'price_quoted' => $reservation->price_quoted],
        ], 201);
    }

    /** GET /api/reservations/{id} */
    public function show(Request $request, int $reservationId)
    {
        $reservation = PropertyReservation::with(['property', 'statusHistory'])->find($reservationId);

        if (! $reservation || $reservation->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        return response()->json(['reservation' => $reservation]);
    }
}
