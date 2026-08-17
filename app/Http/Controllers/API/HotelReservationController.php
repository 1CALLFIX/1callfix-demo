<?php

namespace App\Http\Controllers\API;

use App\Actions\CreateHotelReservationAction;
use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\HotelReservation;
use Illuminate\Http\Request;

/**
 * HOTEL / STAY BOOKING MODULE — the real customer-facing reservation
 * endpoint this vertical's own genuine self-service model justifies, same
 * reasoning PropertyReservationController's own docblock gives. Ownership
 * is checked the same IDOR-safe way every other customer-facing API
 * endpoint in this app already does — a reservation id alone is never
 * sufficient, `customer_id` must match the caller.
 *
 * No customer-initiated cancel endpoint here, matching
 * PropertyReservationController's own established scope exactly (Property
 * Rental, the closest real precedent this codebase has, doesn't expose one
 * either — cancellation is admin/operator-mediated via
 * `AdminCancelHotelReservationAction` behind `hotel_reservations.cancel`).
 * Not an oversight; the same "don't build unsupported customer self-service
 * beyond what the nearest real precedent already justifies" restraint.
 */
class HotelReservationController extends Controller
{
    /** GET /api/hotel-reservations/mine */
    public function mine(Request $request)
    {
        $reservations = HotelReservation::where('customer_id', $request->user()->id)
            ->with(['accommodation', 'rooms.roomType', 'rooms.ratePlan'])
            ->latest()
            ->get();

        return response()->json(['reservations' => $reservations]);
    }

    /**
     * POST /api/hotel-reservations
     * Body: { accommodation_id, check_in_date, check_out_date, number_of_adults,
     *         number_of_children, rooms: [{room_type_id, rate_plan_id, room_count}],
     *         guests: [{name, guest_type, is_primary, phone, email}],
     *         payment_method, special_requests }
     */
    public function store(Request $request, CreateHotelReservationAction $action)
    {
        $validated = $request->validate([
            'accommodation_id' => 'required|integer|exists:accommodations,id',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'number_of_adults' => 'nullable|integer|min:1',
            'number_of_children' => 'nullable|integer|min:0',
            'payment_method' => 'nullable|in:online,wallet,cash',
            'special_requests' => 'nullable|string',
            'rooms' => 'required|array|min:1',
            'rooms.*.room_type_id' => 'required|integer',
            'rooms.*.rate_plan_id' => 'required|integer',
            'rooms.*.room_count' => 'nullable|integer|min:1',
            'guests' => 'nullable|array',
            'guests.*.name' => 'required_with:guests|string|max:255',
            'guests.*.guest_type' => 'nullable|in:adult,child',
            'guests.*.is_primary' => 'nullable|boolean',
            'guests.*.phone' => 'nullable|string|max:20',
            'guests.*.email' => 'nullable|email',
        ]);

        $accommodation = Accommodation::find($validated['accommodation_id']);
        if (! $accommodation) {
            return response()->json(['message' => 'Accommodation not found.'], 404);
        }

        try {
            $reservation = $action->execute([
                'franchise_id' => $accommodation->franchise_id,
                'zone_id' => $accommodation->zone_id,
                'customer_id' => $request->user()->id,
                'accommodation_id' => $accommodation->id,
                'check_in_date' => $validated['check_in_date'],
                'check_out_date' => $validated['check_out_date'],
                'number_of_adults' => $validated['number_of_adults'] ?? 1,
                'number_of_children' => $validated['number_of_children'] ?? 0,
                'rooms' => $validated['rooms'],
                'guests' => $validated['guests'] ?? [],
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
                'price_quoted' => $reservation->price_quoted, 'number_of_rooms' => $reservation->number_of_rooms,
            ],
        ], 201);
    }

    /** GET /api/hotel-reservations/{id} */
    public function show(Request $request, int $reservationId)
    {
        $reservation = HotelReservation::with(['accommodation', 'rooms.roomType', 'rooms.ratePlan', 'guests', 'statusHistory'])
            ->find($reservationId);

        if (! $reservation || $reservation->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        return response()->json(['reservation' => $reservation]);
    }
}
