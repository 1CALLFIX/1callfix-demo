<?php

namespace App\Services\Coupons;

use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\FlashSaleRedemption;
use App\Models\Franchise;
use App\Models\User;
use App\Models\UsageLedger;
use App\Support\Modules;
use Illuminate\Support\Collection;

/**
 * Builds the PromotionContext for the Service module (single booking and
 * bundle). Modules own context building; the engine owns the rules. Every
 * amount is read from the database rows the pricing cascade already wrote.
 */
class ServicePromotionContextBuilder
{
    /**
     * A single booking that already exists (created at its gross price).
     * $flashApplied comes from the pricing cascade; the member-vs-coupon
     * comparison (Q8) is done by the caller, so no entitlement flag here.
     */
    public function forBooking(Booking $booking, ?string $code, bool $flashApplied = false): PromotionContext
    {
        return $this->make($booking, [$this->line($booking, $flashApplied, false)], $code, [$booking->id]);
    }

    /**
     * A read-only preview with no booking yet (CouponQuoteService). $lines: service + the server-resolved unit
     * price + whether a flash sale priced it. Never takes an amount from a client.
     *
     * @param  array<int, array{service: \App\Models\Service, unit_price: float, flash_applied: bool}>  $lines
     */
    public function forQuote(User $customer, ?int $franchiseId, ?int $zoneId, ?Franchise $franchise, array $lines, string $paymentMethod, ?string $code): PromotionContext
    {
        $built = [];
        foreach ($lines as $i => $line) {
            $service = $line['service'];
            $built[] = [
                'line_ref' => 'q'.$i,
                'category_id' => $service->category_id,
                'subcategory_id' => $service->subcategory_id,
                'service_id' => $service->id,
                'line_total' => (float) $line['unit_price'],
                'flash_applied' => (bool) $line['flash_applied'],
                'entitlement_covered' => false,
            ];
        }

        return new PromotionContext(
            module: Modules::SERVICE,
            franchiseId: $franchiseId,
            cityId: $franchise?->city_id,
            zoneId: $zoneId,
            customer: $customer,
            paymentMethod: $paymentMethod,
            lines: $built,
            code: $code,
            ignoreBookingIds: [],
            countryId: $franchise?->country_id,
        );
    }

    /** @param  Collection<int, Booking>  $children */
    public function forBundle(BookingBundle $bundle, Collection $children, ?string $code): PromotionContext
    {
        $lines = $children->map(fn (Booking $child) => $this->line(
            $child,
            FlashSaleRedemption::where('booking_id', $child->id)->exists(),
            // A child already priced through a Prime/member entitlement never takes a coupon too (one discount per booking).
            UsageLedger::where('booking_id', $child->id)->where('event_type', 'consume')->exists(),
        ))->all();

        $ctx = $this->make($bundle, $lines, $code, $children->pluck('id')->all(), $children->first());

        return $ctx;
    }

    private function line(Booking $booking, bool $flashApplied, bool $entitlementCovered): array
    {
        $service = $booking->service;

        return [
            'line_ref' => (string) $booking->id,
            'category_id' => $service?->category_id,
            'subcategory_id' => $service?->subcategory_id,
            'service_id' => $booking->service_id,
            'line_total' => (float) $booking->price_quoted,
            'flash_applied' => $flashApplied,
            'entitlement_covered' => $entitlementCovered,
        ];
    }

    private function make(Booking|BookingBundle $order, array $lines, ?string $code, array $ignoreIds, ?Booking $anchor = null): PromotionContext
    {
        $franchise = $order->franchise;

        return new PromotionContext(
            module: Modules::SERVICE,
            franchiseId: $order->franchise_id,
            cityId: $franchise?->city_id,
            zoneId: $order->zone_id,
            customer: $order->customer,
            paymentMethod: (string) $order->payment_method,
            lines: $lines,
            code: $code,
            ignoreBookingIds: $ignoreIds,
            countryId: $franchise?->country_id,
        );
    }
}
