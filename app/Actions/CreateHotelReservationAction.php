<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Models\Accommodation;
use App\Models\Franchise;
use App\Models\HotelGuest;
use App\Models\HotelRatePlan;
use App\Models\HotelReservation;
use App\Models\HotelReservationRoom;
use App\Models\HotelRoomAvailability;
use App\Models\HotelRoomType;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\HotelAvailabilityService;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HOTEL / STAY BOOKING MODULE — the HotelReservation counterpart to
 * CreatePropertyReservationAction, extended for the real difference this
 * vertical has: MULTIPLE room-type/rate-plan lines within one reservation
 * (`$data['rooms']`, per the mission brief's own explicit multi-room
 * requirement), each locked against `HotelAvailabilityService` inside the
 * same transaction — a conflict on ANY line rolls back the whole
 * reservation (and every line already locked before it), same
 * "no orphaned partial reservation" guarantee
 * CreatePropertyReservationAction's own test suite already proves for the
 * single-line case.
 *
 * Starts at `status = 'pending'` — confirmation is a deliberately separate
 * step, same reasoning as Property Rental.
 */
class CreateHotelReservationAction
{
    public function __construct(
        private ModuleActivationService $moduleActivation,
        private HotelAvailabilityService $availability,
    ) {
    }

    public function execute(array $data): HotelReservation
    {
        $franchise = Franchise::findOrFail($data['franchise_id']);
        $scope = [
            'zone_id' => $data['zone_id'] ?? null,
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ];
        if (! $this->moduleActivation->isActive(Modules::HOTEL, $scope)) {
            throw new ModuleNotActiveException(Modules::HOTEL);
        }

        $accommodation = Accommodation::findOrFail($data['accommodation_id']);

        $checkIn = $data['check_in_date'];
        $checkOut = $data['check_out_date'];
        $this->assertValidDateRange($checkIn, $checkOut);
        $numberOfNights = $this->availability->dateRange($checkIn, $checkOut)->count();

        $roomLines = $data['rooms'] ?? [];
        if (empty($roomLines)) {
            throw new \RuntimeException('At least one room must be selected.');
        }

        // Resolve + price every line server-side -- never trust a
        // client-supplied price/room-type/rate-plan id without verifying
        // it actually belongs to this accommodation and is currently active.
        $resolved = [];
        $totalRooms = 0;
        $totalAdultCapacity = 0;
        $totalPrice = 0.0;

        foreach ($roomLines as $line) {
            $roomType = HotelRoomType::where('accommodation_id', $accommodation->id)
                ->where('is_active', true)
                ->find($line['room_type_id'] ?? null);
            if (! $roomType) {
                throw new \RuntimeException('One or more selected room types are invalid for this accommodation.');
            }

            $ratePlan = HotelRatePlan::where('hotel_room_type_id', $roomType->id)
                ->where('is_active', true)
                ->find($line['rate_plan_id'] ?? null);
            if (! $ratePlan) {
                throw new \RuntimeException('One or more selected rate plans are invalid for this room type.');
            }

            $count = max(1, (int) ($line['room_count'] ?? 1));
            $lineTotal = $this->quoteLine($roomType, $ratePlan, $checkIn, $checkOut, $count);

            $totalRooms += $count;
            $totalAdultCapacity += $roomType->max_adults * $count;
            $totalPrice += $lineTotal;

            $resolved[] = compact('roomType', 'ratePlan', 'count', 'lineTotal');
        }

        $numberOfAdults = (int) ($data['number_of_adults'] ?? 1);
        if ($numberOfAdults > $totalAdultCapacity) {
            throw new \RuntimeException("The selected room(s) can accommodate at most {$totalAdultCapacity} adult(s).");
        }

        $paymentMethod = $data['payment_method'] ?? 'online';

        $reservation = DB::transaction(function () use ($data, $accommodation, $checkIn, $checkOut, $numberOfNights, $totalRooms, $numberOfAdults, $totalPrice, $paymentMethod, $resolved) {
            $reservation = HotelReservation::create([
                'franchise_id' => $accommodation->franchise_id,
                'zone_id' => $accommodation->zone_id,
                'customer_id' => $data['customer_id'],
                'accommodation_id' => $accommodation->id,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'number_of_nights' => $numberOfNights,
                'number_of_rooms' => $totalRooms,
                'number_of_adults' => $numberOfAdults,
                'number_of_children' => (int) ($data['number_of_children'] ?? 0),
                'status' => 'pending',
                'price_quoted' => $totalPrice,
                'payment_method' => $paymentMethod,
                'special_requests' => $data['special_requests'] ?? null,
            ]);

            foreach ($resolved as $line) {
                // The real concurrency-safety step -- see
                // HotelAvailabilityService's own class docblock. A conflict
                // here throws and rolls back the whole reservation,
                // including any line already reserved before it.
                $this->availability->reserveRooms($line['roomType'], $checkIn, $checkOut, $line['count']);

                HotelReservationRoom::create([
                    'hotel_reservation_id' => $reservation->id,
                    'hotel_room_type_id' => $line['roomType']->id,
                    'hotel_rate_plan_id' => $line['ratePlan']->id,
                    'room_count' => $line['count'],
                    'nightly_rate_snapshot' => $line['ratePlan']->nightly_rate,
                    'line_total' => $line['lineTotal'],
                ]);
            }

            foreach ($data['guests'] ?? [] as $guest) {
                if (empty($guest['name'])) {
                    continue;
                }
                HotelGuest::create([
                    'hotel_reservation_id' => $reservation->id,
                    'name' => $guest['name'],
                    'guest_type' => $guest['guest_type'] ?? 'adult',
                    'is_primary' => $guest['is_primary'] ?? false,
                    'phone' => $guest['phone'] ?? null,
                    'email' => $guest['email'] ?? null,
                ]);
            }

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($reservation);
            }

            return $reservation;
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('created', $reservation, $channels));
        }

        return $reservation;
    }

    private function assertValidDateRange(string $checkIn, string $checkOut): void
    {
        $checkInDate = Carbon::parse($checkIn)->startOfDay();
        $checkOutDate = Carbon::parse($checkOut)->startOfDay();

        if (! $checkOutDate->gt($checkInDate)) {
            throw new \RuntimeException('Check-out date must be after check-in date.');
        }
    }

    /**
     * Real per-date price-override support, same mechanism/reasoning as
     * CreatePropertyReservationAction::quote() -- a `HotelRoomAvailability`
     * row's `price_override` (e.g. a seasonal/peak-date rate for this room
     * type) wins over the rate plan's own `nightly_rate` for that specific
     * date, applied per room booked.
     */
    private function quoteLine(HotelRoomType $roomType, HotelRatePlan $ratePlan, string $checkIn, string $checkOut, int $count): float
    {
        $dates = $this->availability->dateRange($checkIn, $checkOut);

        $overrides = HotelRoomAvailability::where('hotel_room_type_id', $roomType->id)
            ->whereIn('date', $dates)
            ->whereNotNull('price_override')
            ->pluck('price_override', 'date');

        $nightlyTotal = 0.0;
        foreach ($dates as $date) {
            $nightlyTotal += (float) ($overrides[$date] ?? $ratePlan->nightly_rate);
        }

        return round($nightlyTotal * $count, 2);
    }

    private function payWithWallet(HotelReservation $reservation): void
    {
        $scope = array_filter(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $reservation->customer,
            (float) $reservation->price_quoted,
            reason: "Payment for reservation {$reservation->code}",
            ref: "hotel_reservation:{$reservation->id}:wallet-payment"
        );

        Payment::create([
            'hotel_reservation_id' => $reservation->id,
            'purpose' => 'hotel_reservation',
            'amount' => $reservation->price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $reservation->payment_status = 'paid';
        $reservation->save();
    }
}
