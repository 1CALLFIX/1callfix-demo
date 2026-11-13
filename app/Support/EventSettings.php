<?php

namespace App\Support;

/**
 * Events — every value Super Admin sets on Admin → Event Settings (owner 2026-11-13: "you decide the settings"). Same
 * types, cascade (zone → franchise → city → country → global), validation and audit log as Shop / Food / Hotel
 * Settings; only the keys differ.
 *
 * Fixed rules (not settings): every organiser and every event is approved by the 1CallFix team before tickets go on
 * sale; tickets are paid online only (Razorpay or wallet); an event the organiser cancels refunds every buyer in full.
 */
class EventSettings extends MarketplaceSettings
{
    public const REGISTRY = [
        // ── joining ──
        'event.recruiting_organisers' => [
            'group' => 'Joining', 'label' => 'Take event organiser sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: "Event organiser" on /partners opens the partner sign-up (mobile code, documents). Each organiser waits for approval in Admin → Events. Turn on early so events are ready before Events opens to customers.',
        ],
        'event.categories' => [
            'group' => 'Joining', 'label' => 'Kinds of event an organiser can choose', 'type' => 'text', 'global' => true,
            'default' => "Party / DJ night\nNew Year\nConcert / Music\nComedy\nWorkshop / Class\nSports\nFood festival\nExhibition\nKids\nDevotional\nCollege fest\nOther",
            'help' => 'One per line. Customers filter by them.',
        ],
        // ── selling ──
        'event.max_tickets_per_order' => [
            'group' => 'Selling', 'label' => 'Most tickets in one order', 'type' => 'int', 'unit' => 'tickets', 'min' => 1, 'max' => 50,
            'default' => '10', 'help' => 'An organiser can set a lower limit on a ticket type, never a higher one.',
        ],
        'event.sales_close_minutes' => [
            'group' => 'Selling', 'label' => 'Stop online sales before the start', 'type' => 'int', 'unit' => 'minutes', 'min' => 0, 'max' => 1440,
            'default' => '60', 'help' => 'Online ticket sales stop this long before the event starts. 0 = sell until the start time.',
        ],
        'event.unpaid_hold_minutes' => [
            'group' => 'Selling', 'label' => 'Hold tickets for an unpaid order', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 30,
            'default' => '10', 'help' => 'No payment, no ticket: if payment is not finished in this time the tickets go back on sale. A payment that still arrives later is refunded in full.',
        ],
        // ── money ──
        'event.commission_percent' => [
            'group' => 'Money', 'label' => 'Commission on ticket sales', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 50,
            'default' => '10', 'help' => 'Kept by 1CallFix from each ticket\'s price. An organiser with its own negotiated rate (Admin → Providers) uses that rate instead.',
        ],
        'event.booking_fee_per_ticket' => [
            'group' => 'Money', 'label' => 'Booking fee per ticket', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 500,
            'default' => null, 'help' => 'Added to the buyer\'s total for each ticket and kept by 1CallFix. Blank = no fee.',
        ],
        'event.payout_hold_hours' => [
            'group' => 'Money', 'label' => 'Pay the organiser this long after the event', 'type' => 'int', 'unit' => 'hours', 'min' => 0, 'max' => 720,
            'default' => '48', 'help' => 'Buyers are protected if the event does not happen: ticket money reaches the organiser only after the event, in the next payout.',
        ],
        // ── refunds ──
        'event.buyer_cancel_allowed' => [
            'group' => 'Refunds', 'label' => 'Buyers may cancel their tickets', 'type' => 'switch', 'default' => '0',
            'help' => 'Off (default): tickets are not refundable once bought (like every ticket site). On: a buyer can cancel up to the time below for a refund of the ticket price (the booking fee is kept). An event cancelled by its organiser is always refunded in full.',
        ],
        'event.buyer_cancel_hours' => [
            'group' => 'Refunds', 'label' => 'Buyers may cancel until', 'type' => 'int', 'unit' => 'hours before the start', 'min' => 1, 'max' => 720,
            'default' => '48', 'help' => 'Only when buyers may cancel.',
        ],
        // ── tax ──
        'event.gst_slabs' => [
            'group' => 'Tax', 'label' => 'GST on tickets by ticket price', 'type' => 'text', 'global' => true,
            'default' => null,
            'help' => 'One line per band: "up to price : GST %", highest last with *, for example "500 : 0", "* : 18". Check the current rates with your CA. Blank = no ticket can be sold (the law sets these rates, so they must be entered first).',
        ],
        'event.platform_collects_gst' => [
            'group' => 'Tax', 'label' => '1CallFix collects and pays the GST', 'type' => 'switch', 'default' => '1', 'global' => true,
            'help' => 'On (default): GST is added to the buyer\'s total and paid by 1CallFix as the platform. Off: each organiser pays its own GST. Confirm with your CA.',
        ],
    ];

    public static function recruitingOrganisers(): bool
    {
        return self::on('event.recruiting_organisers');
    }

    /** GST % for one ticket at this price, from the Super Admin bands. Null = not set or not covered: no sale (fail closed). */
    public static function gstPercentFor(float $ticketPrice): ?float
    {
        foreach (self::gstBands() as [$upTo, $percent]) {
            if ($upTo === null || $ticketPrice <= $upTo) {
                return $percent;
            }
        }

        return null;
    }

    /** @return list<array{0: float|null, 1: float}> */
    public static function gstBands(): array
    {
        $bands = [];
        foreach (preg_split('/\R/', (string) self::get('event.gst_slabs')) as $line) {
            if (preg_match('/^\s*(\*|\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $line, $m) === 1) {
                $bands[] = [$m[1] === '*' ? null : (float) $m[1], (float) $m[2]];
            }
        }

        return $bands;
    }

    public static function validate(string $key, mixed $value): ?string
    {
        if ($key === 'event.gst_slabs' && $value !== null && trim((string) $value) !== '') {
            foreach (preg_split('/\R/', trim((string) $value)) as $line) {
                if (trim($line) !== '' && preg_match('/^\s*(\*|\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)\s*%?\s*$/', $line) !== 1) {
                    return "This line is not \"price : GST %\": {$line}";
                }
            }
        }

        return parent::validate($key, $value);
    }
}
