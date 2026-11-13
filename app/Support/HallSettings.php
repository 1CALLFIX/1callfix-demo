<?php

namespace App\Support;

/**
 * Function halls — every value Super Admin sets on Admin → Hall Settings (owner 2026-11-13: "you decide the settings").
 * Same types, cascade (zone → franchise → city → country → global), validation and audit log as Shop / Hotel / Event
 * Settings; only the keys differ.
 *
 * Fixed rules (not settings): every hall is approved by the 1CallFix team before customers see it; a live hall's name,
 * address, photos and description wait for the team; bookings are paid online only (Razorpay or wallet), no payment
 * no booking; the owner accepts each booking; a booking the owner declines or cancels is refunded in full.
 */
class HallSettings extends MarketplaceSettings
{
    public const REGISTRY = [
        // ── joining ──
        'hall.recruiting_owners' => [
            'group' => 'Joining', 'label' => 'Take function hall sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: "Function hall owner" on /partners opens the partner sign-up (mobile code, documents). Each hall waits for approval in Admin → Function halls. Turn on early so halls are ready before booking opens to customers.',
        ],
        'hall.types' => [
            'group' => 'Joining', 'label' => 'Kinds of hall an owner can choose', 'type' => 'text', 'global' => true,
            'default' => "Function hall\nBanquet hall\nConvention centre\nOpen lawn / garden\nParty hall\nCommunity hall\nResort / farmhouse",
            'help' => 'One per line. Customers filter by them.',
        ],
        'hall.amenities' => [
            'group' => 'Joining', 'label' => 'Facilities an owner can tick', 'type' => 'text', 'global' => true,
            'default' => "Generator / power backup\nDining hall\nKitchen\nStage\nBride and groom rooms\nDecoration allowed\nOutside caterers allowed\nOutside decorators allowed\nLift\nWheelchair access\nSound system\nValet parking",
            'help' => 'One per line, shown as ticks on the hall page.',
        ],
        // ── booking ──
        'hall.advance_percent' => [
            'group' => 'Booking', 'label' => 'Advance paid online to book', 'type' => 'int', 'unit' => '% of the hall price', 'min' => 10, 'max' => 100,
            'default' => '25', 'help' => 'Paid online when booking (no payment, no booking). 100 = pay in full at booking.',
        ],
        'hall.balance_due_days' => [
            'group' => 'Booking', 'label' => 'Balance due before the event', 'type' => 'int', 'unit' => 'days', 'min' => 0, 'max' => 60,
            'default' => '7', 'help' => 'The rest of the hall price is paid online this many days before the date. A booking made closer than this pays in full.',
        ],
        'hall.owner_accept_hours' => [
            'group' => 'Booking', 'label' => 'Owner must accept within', 'type' => 'int', 'unit' => 'hours', 'min' => 1, 'max' => 24,
            'default' => '2', 'help' => 'The hall owner accepts or declines each paid booking. Not answered in time = declined and refunded in full.',
        ],
        'hall.min_days_ahead' => [
            'group' => 'Booking', 'label' => 'Book at least this far ahead', 'type' => 'int', 'unit' => 'days', 'min' => 0, 'max' => 30,
            'default' => '1', 'help' => '0 = same-day bookings allowed.',
        ],
        'hall.max_days_ahead' => [
            'group' => 'Booking', 'label' => 'Book at most this far ahead', 'type' => 'int', 'unit' => 'days', 'min' => 30, 'max' => 730,
            'default' => '365', 'help' => 'Weddings are often booked months ahead.',
        ],
        'hall.unpaid_hold_minutes' => [
            'group' => 'Booking', 'label' => 'Hold a date for an unpaid booking', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 30,
            'default' => '15', 'help' => 'No payment, no booking: if the advance is not paid in this time the date is free again. A payment that still arrives later is refunded in full.',
        ],
        // ── money ──
        'hall.commission_percent' => [
            'group' => 'Money', 'label' => 'Commission on hall bookings', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 50,
            'default' => '10', 'help' => 'Kept by 1CallFix from the hall price. An owner with its own negotiated rate (Admin → Providers) uses that rate instead.',
        ],
        'hall.booking_fee' => [
            'group' => 'Money', 'label' => 'Booking fee per booking', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 5000,
            'default' => null, 'help' => 'Added to the customer\'s advance and kept by 1CallFix. Blank = no fee.',
        ],
        'hall.payout_hours' => [
            'group' => 'Money', 'label' => 'Pay the owner this long after the event', 'type' => 'int', 'unit' => 'hours', 'min' => 0, 'max' => 720,
            'default' => '24', 'help' => 'Customers are protected if the hall is not given: the money reaches the owner after the event, in the next payout.',
        ],
        'hall.deposit_refund_hours' => [
            'group' => 'Money', 'label' => 'Return the deposit this long after the event', 'type' => 'int', 'unit' => 'hours', 'min' => 0, 'max' => 336,
            'default' => '48', 'help' => 'The refundable deposit (each owner sets its own amount) goes back to the customer unless the owner reports damage in this time. A damage claim is checked by the team (money approval rules).',
        ],
        // ── cancellation by the customer ──
        'hall.cancel_full_days' => [
            'group' => 'Cancellation', 'label' => 'Full refund if cancelled at least', 'type' => 'int', 'unit' => 'days before', 'min' => 1, 'max' => 365,
            'default' => '30', 'help' => 'Everything paid comes back, less the fee below.',
        ],
        'hall.cancel_fee' => [
            'group' => 'Cancellation', 'label' => 'Cancellation fee on a full refund', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 50000,
            'default' => '500', 'help' => 'Kept from a full refund. 0 = no fee.',
        ],
        'hall.cancel_half_days' => [
            'group' => 'Cancellation', 'label' => 'Half the advance back if cancelled at least', 'type' => 'int', 'unit' => 'days before', 'min' => 0, 'max' => 365,
            'default' => '7', 'help' => 'Between this and the full-refund days: half the advance comes back (and any balance already paid). Closer than this: the advance is kept, any balance paid comes back. A booking the owner cancels is always refunded in full.',
        ],
        // ── tax ──
        'hall.gst_slabs' => [
            'group' => 'Tax', 'label' => 'GST on the hall price', 'type' => 'text', 'global' => true,
            'default' => null,
            'help' => 'One line per band: "up to price : GST %", highest last with *, for example "* : 18". Check the current rates with your CA. Blank = no GST is charged (start without GST and add it when you register). Once entered, end with a "*" line so every price is covered.',
        ],
        'hall.platform_collects_gst' => [
            'group' => 'Tax', 'label' => '1CallFix collects and pays the GST', 'type' => 'switch', 'default' => '1', 'global' => true,
            'help' => 'On (default): GST is added to the customer\'s bill and paid by 1CallFix as the booking platform. Off: each hall pays its own GST. Confirm with your CA.',
        ],
    ];

    public static function recruitingOwners(): bool
    {
        return self::on('hall.recruiting_owners');
    }

    /** GST % on this hall price. Blank bands = 0 (GST optional, owner 2026-11-13). Bands that do not cover it = null: no booking. */
    public static function gstPercentFor(float $price): ?float
    {
        $bands = self::gstBands();
        if ($bands === []) {
            return 0.0;
        }
        foreach ($bands as [$upTo, $percent]) {
            if ($upTo === null || $price <= $upTo) {
                return $percent;
            }
        }

        return null;
    }

    /** @return list<array{0: float|null, 1: float}> */
    public static function gstBands(): array
    {
        $bands = [];
        foreach (preg_split('/\R/', (string) self::get('hall.gst_slabs')) as $line) {
            if (preg_match('/^\s*(\*|\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $line, $m) === 1) {
                $bands[] = [$m[1] === '*' ? null : (float) $m[1], (float) $m[2]];
            }
        }

        return $bands;
    }

    public static function validate(string $key, mixed $value): ?string
    {
        if ($key === 'hall.gst_slabs' && $value !== null && trim((string) $value) !== '') {
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
