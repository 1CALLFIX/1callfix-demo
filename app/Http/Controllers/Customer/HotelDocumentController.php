<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\HotelReservation;
use App\Models\Setting;

/**
 * Hotels step 2 (owner 2026-11-12, "stand against the aggregators"): the booking voucher (from confirmation: show it at
 * the desk with the check-in code) and the tax invoice (after check-out), the hotel being the supplier and 1CallFix
 * the platform it was booked through. Printable HTML, saved as PDF by the browser — the same way as the shop invoice.
 * The guest who booked, the hotel's own account, or an admin with hotel_reservations.view; nobody else (404).
 */
class HotelDocumentController extends Controller
{
    public const VOUCHER_STATUSES = ['confirmed', 'checked_in', 'checked_out', 'completed'];

    public const INVOICE_STATUSES = ['checked_out', 'completed'];

    public function voucher(string $code)
    {
        return $this->render($code, 'voucher', self::VOUCHER_STATUSES);
    }

    public function invoice(string $code)
    {
        return $this->render($code, 'invoice', self::INVOICE_STATUSES);
    }

    private function render(string $code, string $kind, array $statuses)
    {
        $r = HotelReservation::where('code', $code)
            ->with(['accommodation.franchise', 'accommodation.provider', 'rooms.roomType', 'rooms.ratePlan', 'guests', 'customer'])
            ->firstOrFail();
        $user = auth()->user();
        $hotel = $r->accommodation;
        $isGuest = $r->customer_id === $user->id;
        $isHotel = $hotel?->provider?->user_id === $user->id;
        $isAdmin = $user->hasPermission('hotel_reservations.view', array_filter([
            'zone_id' => $r->zone_id, 'franchise_id' => $r->franchise_id,
            'city_id' => $hotel?->franchise?->city_id, 'country_id' => $hotel?->franchise?->country_id,
        ]));
        abort_unless($isGuest || $isHotel || $isAdmin, 404);
        abort_unless(in_array($r->status, $statuses, true), 404);

        return view('hotels.document', [
            'r' => $r,
            'hotel' => $hotel,
            'kind' => $kind,
            'guest' => $r->guests->firstWhere('is_primary', true) ?? $r->guests->first(),
            'platformName' => Setting::get('branding.platform_name', '1CallFix'),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ]);
    }
}
