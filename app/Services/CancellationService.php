<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\HotelReservation;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PropertyReservation;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\PaymentStatusNotification;
use App\Notifications\PropertyReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\TaxiRideStatusNotification;

/**
 * Cancellation policy resolution + the fee/refund calculation it drives.
 * Shared by AdminCancelBookingAction today; deliberately not admin-only in
 * shape (calculateFee() only takes a Booking, no admin context) so a future
 * customer-initiated cancellation action can call the exact same method
 * rather than a second implementation.
 *
 * Policy values are read through Setting::get()'s existing Global→Country→
 * City→Zone→Module→Franchise cascade — no new resolver, no new
 * cancellation_policies-table logic. cancellation_policies stays in the
 * schema, unused (same status as before this class existed).
 */
class CancellationService
{
    public function __construct(private PaymentGateway $gateway, private WalletService $walletService)
    {
    }

    /**
     * Elapsed time is measured from booking.created_at, not provider
     * assignment — confirmed decision. Fee never exceeds the quoted price.
     */
    public function calculateFee(Booking $booking): float
    {
        $booking->loadMissing('franchise');

        return $this->calculateFeeGeneric(
            array_filter([
                'zone_id' => $booking->zone_id,
                'franchise_id' => $booking->franchise_id,
                'city_id' => $booking->franchise?->city_id,
                'country_id' => $booking->franchise?->country_id,
            ]),
            $booking->created_at,
            (float) ($booking->price_quoted ?? 0)
        );
    }

    /**
     * Phase 22.4 (Parcel) — the same cancellation-fee policy (free window +
     * flat/percent fee, admin-configurable via the identical Setting keys)
     * applied to a ParcelOrder. Extracted the genuinely shared calculation
     * (elapsed time vs. free window, flat-or-percent, capped at the quoted
     * price) into calculateFeeGeneric() below rather than duplicating it —
     * unlike CommissionService, there's no Parcel-specific wrinkle here at
     * all (no entitlement-override-equivalent exists for cancellation fees
     * on either vertical), so a real shared private helper is the correct
     * minimal abstraction, not a coincidence-of-names one.
     */
    public function calculateFeeForParcelOrder(ParcelOrder $order): float
    {
        $order->loadMissing('franchise');

        return $this->calculateFeeGeneric(
            array_filter([
                'zone_id' => $order->zone_id,
                'franchise_id' => $order->franchise_id,
                'city_id' => $order->franchise?->city_id,
                'country_id' => $order->franchise?->country_id,
            ]),
            $order->created_at,
            (float) ($order->price_quoted ?? 0)
        );
    }

    /** Phase 22.6 (Taxi) — the third caller of the shared fee calculation, same reasoning as calculateFeeForParcelOrder() above. */
    public function calculateFeeForTaxiRide(TaxiRide $ride): float
    {
        $ride->loadMissing('franchise');

        return $this->calculateFeeGeneric(
            array_filter([
                'zone_id' => $ride->zone_id,
                'franchise_id' => $ride->franchise_id,
                'city_id' => $ride->franchise?->city_id,
                'country_id' => $ride->franchise?->country_id,
            ]),
            $ride->created_at,
            (float) ($ride->price_quoted ?? 0)
        );
    }

    /**
     * Phase 22.7 (Property Rental) — deliberately NOT routed through
     * `calculateFeeGeneric()` above: that helper's semantics are "elapsed
     * time since the order was CREATED," which fits a same-day Service/
     * Parcel/Taxi job but not a real-world rental cancellation policy,
     * whose natural question is "how far before CHECK-IN was this
     * cancelled." Forcing rental cancellation through the creation-elapsed
     * helper would silently answer the wrong question, not a genuine
     * simplification — so this method follows the SAME architectural
     * pattern (Setting-driven, no dedicated `cancellation_policies` table
     * row per this codebase's own established precedent — see
     * `properties` migration's own docblock) with rental-appropriate
     * semantics instead.
     */
    public function calculateFeeForPropertyReservation(PropertyReservation $reservation): float
    {
        $reservation->loadMissing('franchise');

        $scope = array_filter([
            'zone_id' => $reservation->zone_id,
            'franchise_id' => $reservation->franchise_id,
            'city_id' => $reservation->franchise?->city_id,
            'country_id' => $reservation->franchise?->country_id,
        ]);

        $freeHoursBeforeCheckIn = (int) Setting::get('rental.free_cancellation_hours_before_checkin', '48', $scope);
        $feeType = Setting::get('rental.cancellation_fee_type', 'flat', $scope);
        $feeValue = (float) Setting::get('rental.cancellation_fee_value', '0', $scope);

        $hoursUntilCheckIn = now()->diffInHours($reservation->check_in_date->startOfDay(), false);

        if ($hoursUntilCheckIn >= $freeHoursBeforeCheckIn) {
            return 0.0;
        }

        $basis = (float) ($reservation->price_quoted ?? 0);
        $fee = $feeType === 'percent' ? round($basis * $feeValue / 100, 2) : $feeValue;

        return round(min($fee, $basis), 2);
    }

    private function calculateFeeGeneric(array $scope, \Illuminate\Support\Carbon $createdAt, float $basis): float
    {
        $freeMinutes = (int) Setting::get('cancellation.free_minutes', '15', $scope);
        $feeType = Setting::get('cancellation.fee_type', 'flat', $scope);
        $feeValue = (float) Setting::get('cancellation.fee_value', '0', $scope);

        $elapsedMinutes = $createdAt->diffInMinutes(now());

        if ($elapsedMinutes <= $freeMinutes) {
            return 0.0;
        }

        $fee = $feeType === 'percent' ? round($basis * $feeValue / 100, 2) : $feeValue;

        return round(min($fee, $basis), 2);
    }

    /**
     * If the booking has a captured payment, refunds (amount paid - fee)
     * and records it on the existing payments/bookings columns. No-op for
     * an unpaid booking — there's nothing to refund, and 'cash' bookings
     * never get a captured Payment row in the first place. 'online'
     * (Razorpay) and 'wallet' payment_method bookings both DO get one
     * (see PaymentController::handlePaymentCaptured() and
     * CreateBookingAction::payWithWallet()) — Payment.gateway distinguishes
     * which refund path applies: Razorpay's API for a real gateway
     * payment, or a straight wallet credit (WalletService, not a direct
     * balance mutation) for a wallet payment — nothing external to refund.
     */
    public function refundIfPaid(Booking $booking, float $fee): void
    {
        $payment = Payment::where('booking_id', $booking->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (!$payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            // The fee consumed the entire payment — nothing left to hand
            // back, and calling Razorpay with a zero/negative amount would
            // be meaningless. payment_status stays 'paid'; cancellation_fee
            // on the booking already records that the full amount was kept.
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $booking->customer,
                $refundAmount,
                reason: "Refund for cancelled booking {$booking->code}",
                ref: "booking:{$booking->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Booking {$booking->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $booking->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $booking->save();

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new PaymentStatusNotification('refunded', $booking, $channels, $refundAmount));
        }
    }

    /**
     * Phase 22.4 (Parcel) — the ParcelOrder counterpart to refundIfPaid()
     * above. Kept as a separate method (not a generalized refundIfPaid(
     * Orderable $order, ...)) because PaymentStatusNotification's own
     * constructor is Booking-typed — generalizing that notification class
     * too, with zero second real consumer to design its interface against
     * beyond this one call site, would be exactly the premature
     * abstraction Phase 22.2 already declined to do speculatively. Parcel
     * gets its own ParcelOrderStatusNotification instead (mirrors
     * BookingStatusNotification's shape) — see that class for the refund
     * copy.
     */
    public function refundIfPaidForParcelOrder(ParcelOrder $order, float $fee): void
    {
        $payment = Payment::where('parcel_order_id', $order->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (!$payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $order->customer,
                $refundAmount,
                reason: "Refund for cancelled parcel order {$order->code}",
                ref: "parcel_order:{$order->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Parcel order {$order->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $order->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $order->save();

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('refunded', $order, $channels, $refundAmount));
        }
    }

    /** Phase 22.6 (Taxi) — the TaxiRide counterpart to refundIfPaidForParcelOrder() above, same reasoning. */
    public function refundIfPaidForTaxiRide(TaxiRide $ride, float $fee): void
    {
        $payment = Payment::where('taxi_ride_id', $ride->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (!$payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $ride->customer,
                $refundAmount,
                reason: "Refund for cancelled ride {$ride->code}",
                ref: "taxi_ride:{$ride->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Ride {$ride->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $ride->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $ride->save();

        if ($ride->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);
            $ride->customer->notify(new TaxiRideStatusNotification('refunded', $ride, $channels, $refundAmount));
        }
    }

    /** Phase 22.7 (Property Rental) — the PropertyReservation counterpart to refundIfPaidForTaxiRide() above, same reasoning. */
    public function refundIfPaidForPropertyReservation(PropertyReservation $reservation, float $fee): void
    {
        $payment = Payment::where('property_reservation_id', $reservation->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (!$payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $reservation->customer,
                $refundAmount,
                reason: "Refund for cancelled reservation {$reservation->code}",
                ref: "property_reservation:{$reservation->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Reservation {$reservation->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $reservation->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $reservation->save();

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new PropertyReservationStatusNotification('refunded', $reservation, $channels, $refundAmount));
        }
    }

    /**
     * RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine
     * counterpart to calculateFeeForPropertyReservation() above, same
     * "elapsed time is measured against the START of use, not creation"
     * reasoning (a rental cancellation policy naturally asks "how far
     * before pickup/start was this cancelled", not "how long has this
     * order existed"). Deliberately its OWN Setting namespace
     * (`rental.vehicle_equipment.*`) rather than reusing Property's
     * `rental.free_cancellation_hours_before_checkin` -- Vehicle/Equipment
     * are a different real product with no evidence they should share
     * Property's exact configured window; an admin can still set both
     * namespaces to the same value, but isn't forced to couple them.
     * Default values (48h / flat / 0) are the same neutral technical
     * defaults Property already ships with, not a new invented number.
     */
    public function calculateFeeForRentalReservation(\App\Models\RentalReservation $reservation): float
    {
        $reservation->loadMissing('franchise');

        $scope = array_filter([
            'zone_id' => $reservation->zone_id,
            'franchise_id' => $reservation->franchise_id,
            'city_id' => $reservation->franchise?->city_id,
            'country_id' => $reservation->franchise?->country_id,
        ]);

        $freeHoursBeforeStart = (int) Setting::get('rental.vehicle_equipment.free_cancellation_hours_before_start', '48', $scope);
        $feeType = Setting::get('rental.vehicle_equipment.cancellation_fee_type', 'flat', $scope);
        $feeValue = (float) Setting::get('rental.vehicle_equipment.cancellation_fee_value', '0', $scope);

        $hoursUntilStart = now()->diffInHours($reservation->starts_at, false);

        if ($hoursUntilStart >= $freeHoursBeforeStart) {
            return 0.0;
        }

        $basis = (float) ($reservation->price_quoted ?? 0);
        $fee = $feeType === 'percent' ? round($basis * $feeValue / 100, 2) : $feeValue;

        return round(min($fee, $basis), 2);
    }

    /** RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine counterpart to refundIfPaidForPropertyReservation() above, same reasoning. */
    public function refundIfPaidForRentalReservation(\App\Models\RentalReservation $reservation, float $fee): void
    {
        $payment = Payment::where('rental_reservation_id', $reservation->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (! $payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $reservation->customer,
                $refundAmount,
                reason: "Refund for cancelled rental reservation {$reservation->code}",
                ref: "rental_reservation:{$reservation->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Rental reservation {$reservation->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $reservation->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $reservation->save();

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new \App\Notifications\RentalReservationStatusNotification('refunded', $reservation, $channels, $refundAmount));
        }
    }

    /**
     * HOTEL / STAY BOOKING MODULE -- deliberately NOT routed through
     * `calculateFeeGeneric()`, same reasoning
     * `calculateFeeForPropertyReservation()` gives above: a real hotel
     * cancellation policy's natural question is "how far before CHECK-IN
     * was this cancelled," not "how long has this order existed." Own
     * Setting namespace (`hotel.*`) rather than reusing Property Rental's
     * `rental.*` keys -- Hotel/Stay is its own module with no evidence it
     * should share Property Rental's configured window; an admin can still
     * set both namespaces identically, but isn't forced to. Default values
     * (48h / flat / 0) are the same neutral technical defaults every other
     * cancellation-fee Setting in this codebase already ships with -- see
     * KNOWN_RISKS_AND_DECISIONS.md item 5's own "safe-zero default"
     * precedent -- not a new invented number.
     */
    public function calculateFeeForHotelReservation(HotelReservation $reservation): float
    {
        $reservation->loadMissing('franchise');

        $scope = array_filter([
            'zone_id' => $reservation->zone_id,
            'franchise_id' => $reservation->franchise_id,
            'city_id' => $reservation->franchise?->city_id,
            'country_id' => $reservation->franchise?->country_id,
        ]);

        $freeHoursBeforeCheckIn = (int) Setting::get('hotel.free_cancellation_hours_before_checkin', '48', $scope);
        $feeType = Setting::get('hotel.cancellation_fee_type', 'flat', $scope);
        $feeValue = (float) Setting::get('hotel.cancellation_fee_value', '0', $scope);

        $hoursUntilCheckIn = now()->diffInHours($reservation->check_in_date->startOfDay(), false);

        if ($hoursUntilCheckIn >= $freeHoursBeforeCheckIn) {
            return 0.0;
        }

        $basis = (float) ($reservation->price_quoted ?? 0);
        $fee = $feeType === 'percent' ? round($basis * $feeValue / 100, 2) : $feeValue;

        return round(min($fee, $basis), 2);
    }

    /** HOTEL / STAY BOOKING MODULE -- the HotelReservation counterpart to refundIfPaidForPropertyReservation() above, same reasoning. */
    public function refundIfPaidForHotelReservation(HotelReservation $reservation, float $fee): void
    {
        $payment = Payment::where('hotel_reservation_id', $reservation->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (! $payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $reservation->customer,
                $refundAmount,
                reason: "Refund for cancelled hotel reservation {$reservation->code}",
                ref: "hotel_reservation:{$reservation->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Hotel reservation {$reservation->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $reservation->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $reservation->save();

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('refunded', $reservation, $channels, $refundAmount));
        }
    }

    /**
     * Phase 24 (Marketplace Foundation) — same elapsed-since-creation
     * generic formula Parcel/Taxi already use (calculateFeeGeneric()), not
     * Property Rental's hours-before-check-in shape -- no evidence supports
     * a marketplace-specific window formula any more than Parcel/Taxi had
     * one, so this follows their precedent rather than Property's.
     */
    public function calculateFeeForMarketplaceOrder(MarketplaceOrder $order): float
    {
        $order->loadMissing('franchise');

        return $this->calculateFeeGeneric(
            array_filter([
                'zone_id' => $order->zone_id,
                'franchise_id' => $order->franchise_id,
                'city_id' => $order->franchise?->city_id,
                'country_id' => $order->franchise?->country_id,
            ]),
            $order->created_at,
            (float) ($order->total_amount ?? 0)
        );
    }

    /** Phase 24 (Marketplace Foundation) — the MarketplaceOrder counterpart to refundIfPaidForPropertyReservation() above, same reasoning. */
    public function refundIfPaidForMarketplaceOrder(MarketplaceOrder $order, float $fee): void
    {
        $payment = Payment::where('marketplace_order_id', $order->id)
            ->where('status', 'captured')
            ->latest()
            ->first();

        if (!$payment) {
            return;
        }

        $refundAmount = round(max((float) $payment->amount - $fee, 0), 2);

        if ($refundAmount <= 0) {
            return;
        }

        if ($payment->gateway === 'wallet') {
            $this->walletService->credit(
                $order->customer,
                $refundAmount,
                reason: "Refund for cancelled order {$order->code}",
                ref: "marketplace_order:{$order->id}:wallet-refund"
            );
        } else {
            $this->gateway->refund(
                $payment->gateway_payment_id,
                $refundAmount,
                "Order {$order->code} cancelled"
            );
        }

        $payment->refunded_amount = $refundAmount;
        $payment->status = 'refunded';
        $payment->save();

        $order->payment_status = $refundAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        $order->save();

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification('refunded', $order, $channels, $refundAmount));
        }
    }
}
