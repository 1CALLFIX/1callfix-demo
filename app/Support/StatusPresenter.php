<?php

namespace App\Support;

/**
 * Admin Polish + AI session — single source of truth for "status -> color +
 * icon + label" across every screen that shows a booking/provider-KYC/
 * payment-ish status pill. Before this, each screen (Dashboard, Bookings\
 * Index, Providers\Show, ...) hand-wrote its own `match($status) => color`
 * block — same five-ish statuses, re-derived per view, and always
 * color-only (a screen reader or colorblind user got no other signal than
 * the pill's background hue). This does not invent any new status value —
 * every key below is a real value already written to its column somewhere
 * in this codebase (see each map's own docblock for the source).
 *
 * Icon names are keys into components/icon.blade.php. Colors are keys into
 * x-ui.badge's own $colors map (see that component) — nothing here invents
 * a color, it only decides which of that existing palette a given status
 * maps to, the same "callers decide the mapping, the component owns the
 * palette" split badge.blade.php's own docblock already establishes.
 */
class StatusPresenter
{
    /**
     * Booking::status — see the Bookings migration + BookingStatusHistory
     * for the authoritative list. 'pending' is the pre-dispatch state
     * (payment not yet confirmed for online/wallet); everything from
     * searching_provider onward is BookingStatusHistory-logged.
     */
    private const BOOKING = [
        'pending' => ['color' => 'gray', 'icon' => 'clock'],
        'searching_provider' => ['color' => 'blue', 'icon' => 'magnifying-glass'],
        'assigned' => ['color' => 'blue', 'icon' => 'check-circle'],
        'provider_en_route' => ['color' => 'blue', 'icon' => 'arrow-path'],
        'in_progress' => ['color' => 'amber', 'icon' => 'arrow-path'],
        'on_hold' => ['color' => 'amber', 'icon' => 'exclamation-triangle'],
        'completed' => ['color' => 'green', 'icon' => 'check-circle'],
        'cancelled' => ['color' => 'red', 'icon' => 'x-circle'],
        'disputed' => ['color' => 'red', 'icon' => 'exclamation-triangle'],
    ];

    /** Provider::kyc_status — ReviewProviderKycAction's own approve()/reject() are the only writers besides the initial 'pending' default. */
    private const PROVIDER_KYC = [
        'pending' => ['color' => 'amber', 'icon' => 'clock'],
        'approved' => ['color' => 'green', 'icon' => 'check-circle'],
        'rejected' => ['color' => 'red', 'icon' => 'x-circle'],
    ];

    /** ProviderDocument::status — set by upload (pending) and ReviewProviderKycAction (approved/rejected alongside the parent provider). */
    private const DOCUMENT = [
        'pending' => ['color' => 'amber', 'icon' => 'clock'],
        'approved' => ['color' => 'green', 'icon' => 'check-circle'],
        'rejected' => ['color' => 'red', 'icon' => 'x-circle'],
    ];

    /** User::status for role='customer' rows — Customers\Index/Show's own pre-existing match() (same three values, just centralized here now, plus an icon). */
    private const CUSTOMER = [
        'active' => ['color' => 'green', 'icon' => 'check-circle'],
        'suspended' => ['color' => 'red', 'icon' => 'x-circle'],
        'pending_verification' => ['color' => 'amber', 'icon' => 'clock'],
    ];

    private const MAPS = [
        'booking' => self::BOOKING,
        'provider_kyc' => self::PROVIDER_KYC,
        'document' => self::DOCUMENT,
        'customer' => self::CUSTOMER,
    ];

    /**
     * @return array{color: string, icon: string, label: string}
     */
    public static function for(string $type, ?string $status): array
    {
        $status ??= '';
        $entry = self::MAPS[$type][$status] ?? ['color' => 'gray', 'icon' => 'clock'];

        return [
            'color' => $entry['color'],
            'icon' => $entry['icon'],
            'label' => str_replace('_', ' ', $status) ?: 'unknown',
        ];
    }
}
