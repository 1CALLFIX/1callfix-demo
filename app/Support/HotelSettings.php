<?php

namespace App\Support;

/**
 * Hotels & stays — every value Super Admin sets on Admin → Hotel Settings (owner 2026-11-12: "decide what is best and
 * scalable and must be controlled by super admin"). Same types, cascade (zone → franchise → city → country → global),
 * validation and audit log as Shop / Food Settings; only the keys differ.
 *
 * Defaults are the owner-approved starting rules (2026-11-12): hotels and lodges first, home stays switched off;
 * full payment online; free cancellation until 24 h before check-in, then 1 night; instant confirmation from the
 * hotel's calendar; GST rates must be entered before anyone can book (fail closed — the law sets them).
 */
class HotelSettings extends MarketplaceSettings
{
    /** Accommodation types that are private homes (Airbnb-style), shown only when home stays are switched on. */
    public const HOME_STAY_TYPES = ['homestay', 'serviced_apartment'];

    public const REGISTRY = [
        // ── joining ──
        'hotel.recruiting_hotels' => [
            'group' => 'Joining', 'label' => 'Take hotel sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: "Hotel or stay host" on /partners opens the partner sign-up (mobile code, documents). Each hotel waits for approval in Admin → Hotels. Turn on before Hotels opens to customers, so rooms and photos are ready.',
        ],
        'hotel.accept_home_stays' => [
            'group' => 'Joining', 'label' => 'Accept home stays and serviced apartments', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'Off (default): only hotels, resorts, guest houses and hostels can join and be booked. On: private homes too (Airbnb-style). Check each home\'s photos and documents carefully before approving.',
        ],
        'hotel.min_photos' => [
            'group' => 'Joining', 'label' => 'Photos a hotel needs before it can be approved', 'type' => 'int', 'unit' => 'photos', 'min' => 0, 'max' => 20,
            'default' => '3', 'help' => 'Counted across the hotel and its rooms. Real photos are what customers trust most.',
        ],
        'hotel.amenities' => [
            'group' => 'Joining', 'label' => 'Facilities a hotel can tick', 'type' => 'text', 'global' => true,
            'default' => "Free Wi-Fi\nAir conditioning\nTV\nHot water\nParking\nPower backup\nLift\nRestaurant\nRoom service\n24-hour front desk\nCCTV\nCouple friendly\nLocal ID accepted\nFamily rooms\nPet friendly",
            'help' => 'One per line. Customers filter by them.',
        ],
        // ── booking ──
        'hotel.booking_window_days' => [
            'group' => 'Booking', 'label' => 'Customers can book up to', 'type' => 'int', 'unit' => 'days ahead', 'min' => 1, 'max' => 730,
            'default' => '180', 'help' => 'How far in the future a check-in date can be.',
        ],
        'hotel.max_nights' => [
            'group' => 'Booking', 'label' => 'Longest stay in one booking', 'type' => 'int', 'unit' => 'nights', 'min' => 1, 'max' => 365,
            'default' => '30', 'help' => 'Longer stays need a second booking or a call to the hotel.',
        ],
        'hotel.max_rooms_per_booking' => [
            'group' => 'Booking', 'label' => 'Most rooms in one booking', 'type' => 'int', 'unit' => 'rooms', 'min' => 1, 'max' => 50,
            'default' => '5', 'help' => 'Bigger group bookings go through the hotel directly.',
        ],
        'hotel.weekend_nights' => [
            'group' => 'Booking', 'label' => 'Nights that use a hotel\'s weekend price', 'type' => 'text', 'global' => true,
            'default' => "Fri\nSat",
            'help' => 'One per line: Mon, Tue, Wed, Thu, Fri, Sat, Sun. A hotel that sets a weekend price charges it on these nights (the night of Friday = check-in Friday).',
        ],
        'hotel.needs_acceptance' => [
            'group' => 'Booking', 'label' => 'Hotel must accept each booking', 'type' => 'switch', 'default' => '0',
            'help' => 'Off (default): booked and confirmed at once from the hotel\'s room calendar. On: the hotel accepts in its portal within the time below, or the booking is cancelled and fully refunded. A single hotel can be set differently in Admin → Hotels.',
        ],
        'hotel.accept_window_minutes' => [
            'group' => 'Booking', 'label' => 'Time a hotel has to accept', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 240,
            'default' => '15', 'help' => 'Only when the hotel must accept bookings. After this the booking is cancelled and the customer refunded in full.',
        ],
        // ── payment ──
        'hotel.pay_at_hotel_allowed' => [
            'group' => 'Payment', 'label' => 'Allow "Pay at hotel"', 'type' => 'switch', 'default' => '0',
            'help' => 'Off (default): full payment online (Razorpay or wallet) when booking. On: customers may pay at the hotel instead. Pay-at-hotel bookings never get a coupon, offer or wallet credit (online-only rule).',
        ],
        'hotel.unpaid_hold_minutes' => [
            'group' => 'Payment', 'label' => 'Hold rooms for an unpaid online booking', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 60,
            'default' => '15', 'help' => 'No payment, no booking: if the customer does not finish paying in this time, the booking is cancelled and the rooms are free again. A payment that still arrives later is refunded in full.',
        ],
        'hotel.convenience_fee' => [
            'group' => 'Payment', 'label' => 'Convenience fee per booking', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 2000,
            'default' => null, 'help' => 'Added to the customer\'s total and kept by 1CallFix. Blank = no fee.',
        ],
        'hotel.commission_percent' => [
            'group' => 'Payment', 'label' => 'Commission on the room price', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 50,
            'default' => '15', 'help' => 'Kept by 1CallFix from each completed stay. A hotel with its own negotiated rate (Admin → Providers) uses that rate instead.',
        ],
        'hotel.payout_hold_hours' => [
            'group' => 'Payment', 'label' => 'Pay the hotel this long after check-out', 'type' => 'int', 'unit' => 'hours', 'min' => 0, 'max' => 720,
            'default' => '24', 'help' => 'Time for a guest to report a problem before the stay is added to the hotel\'s next payout.',
        ],
        // ── cancellation ──
        'hotel.free_cancel_hours' => [
            'group' => 'Cancellation', 'label' => 'Free cancellation until', 'type' => 'int', 'unit' => 'hours before check-in', 'min' => 0, 'max' => 720,
            'default' => '24', 'help' => 'Cancelled earlier: full refund. 0 = free cancellation until check-in time.',
        ],
        'hotel.late_cancel_nights' => [
            'group' => 'Cancellation', 'label' => 'Charge for a late cancellation', 'type' => 'int', 'unit' => 'nights', 'min' => 0, 'max' => 30,
            'default' => '1', 'help' => 'Cancelled after the free time: this many nights are charged, the rest refunded. 0 = always free.',
        ],
        'hotel.no_show_nights' => [
            'group' => 'Cancellation', 'label' => 'Charge when the guest does not arrive', 'type' => 'int', 'unit' => 'nights', 'min' => 0, 'max' => 30,
            'default' => '1', 'help' => 'The hotel marks "Did not arrive" after check-in day; this many nights are charged, the rest refunded.',
        ],
        'hotel.non_refundable_allowed' => [
            'group' => 'Cancellation', 'label' => 'Hotels may offer a cheaper non-refundable price', 'type' => 'switch', 'default' => '1',
            'help' => 'On: a hotel can set "Non-refundable discount %" on a room; guests then see both prices. A non-refundable booking keeps the whole amount if cancelled or the guest does not arrive, and its dates cannot be changed.',
        ],
        'hotel.date_change_allowed' => [
            'group' => 'Cancellation', 'label' => 'Guests may change dates', 'type' => 'switch', 'default' => '1',
            'help' => 'On: while free cancellation still applies, the guest can pick new dates at the same hotel. They pay the new stay; once it is confirmed the old booking is cancelled free and refunded in full.',
        ],
        // ── tax ──
        'hotel.gst_slabs' => [
            'group' => 'Tax', 'label' => 'GST on rooms by price per night', 'type' => 'text', 'global' => true,
            'default' => null,
            'help' => 'One line per band: "up to price : GST %", highest last with *, for example "1000 : 0", "7500 : 5", "* : 18". Check the current rates with your CA. Blank = no GST is charged (start without GST and add the bands when you register for it). Once entered, end with a "*" line so every price is covered.',
        ],
        'hotel.platform_collects_gst' => [
            'group' => 'Tax', 'label' => '1CallFix collects and pays the GST', 'type' => 'switch', 'default' => '1', 'global' => true,
            'help' => 'On (default): GST is added to the customer\'s bill and paid by 1CallFix as the booking platform (needed for hotels without a GST number). Off: each hotel pays its own GST. Confirm with your CA.',
        ],
    ];

    public static function recruitingHotels(): bool
    {
        return self::on('hotel.recruiting_hotels');
    }

    public static function homeStaysAllowed(): bool
    {
        return self::on('hotel.accept_home_stays');
    }

    /**
     * GST % for one night at this price, from the Super Admin bands. Blank bands = 0 (GST is optional, owner 2026-11-13).
     * Bands that do not cover the price = null: booking is refused (fail closed).
     */
    public static function gstPercentFor(float $nightlyPrice, ?array $bands = null): ?float
    {
        $bands ??= self::gstBands();
        if ($bands === []) {
            return 0.0;
        }
        foreach ($bands as [$upTo, $percent]) {
            if ($upTo === null || $nightlyPrice <= $upTo) {
                return $percent;
            }
        }

        return null;
    }

    /** @return list<array{0: float|null, 1: float}> [up to price (null = any), GST %], in the order typed */
    public static function gstBands(): array
    {
        $bands = [];
        foreach (preg_split('/\R/', (string) self::get('hotel.gst_slabs')) as $line) {
            if (preg_match('/^\s*(\*|\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $line, $m) === 1) {
                $bands[] = [$m[1] === '*' ? null : (float) $m[1], (float) $m[2]];
            }
        }

        return $bands;
    }

    public static function validate(string $key, mixed $value): ?string
    {
        if ($key === 'hotel.gst_slabs' && $value !== null && trim((string) $value) !== '') {
            foreach (preg_split('/\R/', trim((string) $value)) as $line) {
                if (trim($line) !== '' && preg_match('/^\s*(\*|\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $line) !== 1) {
                    return "This line is not \"price : GST %\": {$line}";
                }
            }
            if (preg_match('/^\s*\*\s*:/m', (string) $value) !== 1) {
                return 'Add a last line for every higher price, like "* : 18".';
            }
        }

        return parent::validate($key, $value);
    }
}
