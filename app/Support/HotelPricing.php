<?php

namespace App\Support;

use App\Models\Accommodation;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomAvailability;
use App\Models\HotelRoomType;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Hotels H2 — the one price calculator for a stay (owner 2026-11-12: "stand against the aggregators": every price a
 * customer sees includes taxes, no surprise at checkout). Used by the city list, the hotel page and the booking, so
 * the number on the card is the number paid.
 *
 * Per night and per room: the calendar's special price wins, then the hotel's weekend price on Hotel Settings'
 * weekend nights, then its normal price. GST is worked out per night from that night's room price (Hotel Settings
 * bands); when no band covers it the quote says so and the stay cannot be booked (fail closed). The convenience fee
 * (Hotel Settings, blank = none) is added once per booking.
 */
final class HotelPricing
{
    /**
     * @return array{
     *   nights: list<array{date: string, price: float, gst_percent: float|null}>,
     *   rooms: int, room_total: float, gst: float, fee: float, total: float, per_night_with_tax: float,
     *   taxes_known: bool, left: int, available: bool
     * }
     */
    public static function quote(HotelRoomType $room, HotelRatePlan $plan, string $checkIn, string $checkOut, int $rooms = 1): array
    {
        $hotel = $room->accommodation;
        $scope = $hotel ? self::scope($hotel) : [];
        $dates = collect(CarbonPeriod::create($checkIn, Carbon::parse($checkOut)->subDay()))->map->toDateString();
        $rows = HotelRoomAvailability::where('hotel_room_type_id', $room->id)->whereIn('date', $dates)->get()
            ->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());
        $weekend = array_map(fn ($d) => ucfirst(strtolower(substr($d, 0, 3))), HotelSettings::lines('hotel.weekend_nights'));

        $nights = [];
        $roomTotal = 0.0;
        $gst = 0.0;
        $taxesKnown = true;
        $left = PHP_INT_MAX;
        $open = true;
        foreach ($dates as $date) {
            $row = $rows->get($date);
            $price = $row?->price_override !== null ? (float) $row->price_override
                : ($plan->weekend_rate !== null && in_array(Carbon::parse($date)->format('D'), $weekend, true) ? (float) $plan->weekend_rate : (float) $plan->nightly_rate);
            $percent = HotelSettings::gstPercentFor($price);
            $taxesKnown = $taxesKnown && $percent !== null;
            $roomTotal += $price * $rooms;
            $gst += $percent !== null ? round($price * $percent / 100, 2) * $rooms : 0;
            $left = min($left, max(0, $room->total_inventory - (int) ($row->rooms_booked ?? 0)));
            $open = $open && ($row === null || $row->is_available);
            $nights[] = ['date' => $date, 'price' => $price, 'gst_percent' => $percent];
        }
        $left = $left === PHP_INT_MAX ? (int) $room->total_inventory : $left;

        // GST is the customer's only when 1CallFix collects it; otherwise the hotel's price already carries it.
        $collect = HotelSettings::on('hotel.platform_collects_gst');
        $gst = $collect ? round($gst, 2) : 0.0;
        $fee = (float) (HotelSettings::get('hotel.convenience_fee', $scope) ?? 0);
        $total = round($roomTotal + $gst + $fee, 2);
        $count = max(1, count($nights));

        return [
            'nights' => $nights,
            'rooms' => $rooms,
            'room_total' => round($roomTotal, 2),
            'gst' => $gst,
            'fee' => $fee,
            'total' => $total,
            'per_night_with_tax' => round(($roomTotal + $gst) / $count / max(1, $rooms), 2),
            'taxes_known' => $taxesKnown || ! $collect,
            'left' => $open ? $left : 0,
            'available' => $open && $left >= $rooms,
        ];
    }

    /** The cheapest room tonight-style quote for a hotel card: [room, plan, quote] or null when nothing is open. */
    public static function cheapest(Accommodation $hotel, string $checkIn, string $checkOut, int $rooms = 1, int $adults = 1): ?array
    {
        $best = null;
        foreach ($hotel->roomTypes as $room) {
            if (! $room->is_active || $room->max_adults * $rooms < $adults) {
                continue;
            }
            $plan = $room->ratePlans->where('is_active', true)->sortBy('nightly_rate')->first();
            if (! $plan || (float) $plan->nightly_rate <= 0) {
                continue;
            }
            $quote = self::quote($room, $plan, $checkIn, $checkOut, $rooms);
            if ($quote['available'] && ($best === null || $quote['total'] < $best[2]['total'])) {
                $best = [$room, $plan, $quote];
            }
        }

        return $best;
    }

    /** Last moment to cancel for free, in the hotel's own time (null = free cancellation switched off). */
    public static function freeCancelUntil(Accommodation $hotel, string $checkIn): ?Carbon
    {
        $scope = self::scope($hotel);
        if ((int) HotelSettings::get('hotel.late_cancel_nights', $scope) === 0) {
            return null; // always free
        }
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($hotel->franchise);
        $arrival = Carbon::parse($checkIn.' '.substr((string) ($hotel->check_in_time_start ?: '14:00:00'), 0, 8), $tz);

        return $arrival->subHours((int) HotelSettings::get('hotel.free_cancel_hours', $scope));
    }

    public static function scope(Accommodation $hotel): array
    {
        return array_filter([
            'zone_id' => $hotel->zone_id, 'franchise_id' => $hotel->franchise_id,
            'city_id' => $hotel->franchise?->city_id, 'country_id' => $hotel->franchise?->country_id,
        ]);
    }
}
