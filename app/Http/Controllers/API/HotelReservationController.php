<?php

namespace App\Http\Controllers\API;

use App\Exceptions\ModuleNotActiveException;
use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\HotelRatePlan;
use App\Models\HotelReservation;
use App\Models\HotelRoomType;
use App\Services\Hotels\HotelBookingService;
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
     *         rooms: [{room_type_id, rate_plan_id, room_count}] (one room type per booking, like the website),
     *         guests: [{name, phone, is_primary}], payment_method: online|wallet, special_requests,
     *         gst_number?, business_name?, number_of_children?, coupon_code? (online / wallet only) }
     *
     * Owner 2026-11-12: the app books exactly like the website — the same HotelBookingService, so only approved
     * hotels, the HotelPricing total with GST, online or wallet only (never cash), the unpaid hold, the stay and
     * booking-window limits. An online booking is paid with POST /api/hotel-reservations/{id}/pay.
     */
    public function store(Request $request, HotelBookingService $bookings)
    {
        $validated = $request->validate([
            'accommodation_id' => 'required|integer',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'number_of_adults' => 'nullable|integer|min:1',
            'number_of_children' => 'nullable|integer|min:0|max:20',
            'coupon_code' => 'nullable|string|max:50',
            'payment_method' => 'nullable|in:online,wallet',
            'special_requests' => 'nullable|string|max:500',
            'gst_number' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Za-z0-9]{13}$/'],
            'business_name' => 'nullable|required_with:gst_number|string|max:120',
            'rooms' => 'required|array|size:1',
            'rooms.*.room_type_id' => 'required|integer',
            'rooms.*.rate_plan_id' => 'required|integer',
            'rooms.*.room_count' => 'nullable|integer|min:1',
            'guests' => 'nullable|array',
            'guests.*.name' => 'required_with:guests|string|min:2|max:120',
            'guests.*.is_primary' => 'nullable|boolean',
            'guests.*.phone' => 'nullable|string|max:20',
        ], ['rooms.size' => 'Book one room type at a time.']);

        $hotel = Accommodation::bookable()->with(['franchise', 'accommodationType'])->find($validated['accommodation_id']);
        $line = $validated['rooms'][0];
        $room = $hotel ? HotelRoomType::where('accommodation_id', $hotel->id)->where('is_active', true)->find($line['room_type_id']) : null;
        $plan = $room ? HotelRatePlan::where('hotel_room_type_id', $room->id)->where('is_active', true)->find($line['rate_plan_id']) : null;
        if (! $hotel || ! $room || ! $plan) {
            return response()->json(['message' => 'This room cannot be booked right now.'], 404);
        }

        $user = $request->user();
        $guest = collect($validated['guests'] ?? [])->firstWhere('is_primary', true) ?? ($validated['guests'][0] ?? []);

        try {
            $reservation = $bookings->book($user, $hotel, $room, $plan,
                $validated['check_in_date'], $validated['check_out_date'],
                (int) ($line['room_count'] ?? 1), (int) ($validated['number_of_adults'] ?? 1), [
                    'guest_name' => $guest['name'] ?? (string) $user->name,
                    'guest_phone' => $guest['phone'] ?? $user->phone,
                    'special_requests' => $validated['special_requests'] ?? null,
                    'gst_number' => $validated['gst_number'] ?? null,
                    'business_name' => $validated['business_name'] ?? null,
                ], $validated['payment_method'] ?? 'online', $validated['coupon_code'] ?? null, (int) ($validated['number_of_children'] ?? 0));
        } catch (ModuleNotActiveException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => $reservation->payment_status === 'paid' ? 'Booked and paid.' : 'Booked. Pay within the time shown to keep the rooms.',
            'reservation' => [
                'id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status,
                'payment_method' => $reservation->payment_method, 'payment_status' => $reservation->payment_status,
                'price_quoted' => $reservation->price_quoted, 'tax_amount' => $reservation->tax_amount,
                'convenience_fee' => $reservation->convenience_fee, 'amount_due' => $reservation->amount_due,
                'coupon_code' => $reservation->coupon_code, 'coupon_discount_amount' => $reservation->coupon_discount_amount,
                'number_of_children' => $reservation->number_of_children,
                'payment_expires_at' => $reservation->payment_expires_at?->toIso8601String(),
                'number_of_rooms' => $reservation->number_of_rooms,
            ],
        ], 201);
    }

    /** POST /api/hotel-reservations/{id}/pay — a Razorpay order for an unpaid online booking (the webhook confirms it). */
    public function pay(Request $request, int $reservationId, HotelBookingService $bookings)
    {
        $reservation = HotelReservation::find($reservationId);
        if (! $reservation || $reservation->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        try {
            return response()->json(['payment' => $bookings->startOnlinePayment($reservation)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
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
