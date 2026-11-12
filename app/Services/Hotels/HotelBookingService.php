<?php

namespace App\Services\Hotels;

use App\Actions\CreateHotelReservationAction;
use App\Contracts\PaymentGateway;
use App\Models\Accommodation;
use App\Models\HotelRatePlan;
use App\Models\HotelReservation;
use App\Models\HotelRoomType;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\CancellationService;
use App\Services\Coupons\CouponService;
use App\Services\Coupons\PromotionContext;
use App\Services\Coupons\PromotionResult;
use App\Services\HotelAvailabilityService;
use App\Support\HotelPricing;
use App\Support\HotelSettings;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hotels H2b — booking and paying for a stay from the customer pages, on the existing engine
 * (CreateHotelReservationAction locks the rooms; HotelPricing sets every amount).
 *
 * No payment, no booking (owner 2026-11-07): an online booking holds its rooms only for Hotel Settings' unpaid hold
 * minutes; the Razorpay capture WEBHOOK (never the browser) marks it paid, then it is confirmed at once unless the
 * hotel must accept bookings (H3 adds the accept step). A capture that arrives after the hold expired, or a second
 * capture, is refunded in full. Wallet pays at once (the customer's own money; not a benefit).
 */
class HotelBookingService
{
    public function __construct(
        private CreateHotelReservationAction $create,
        private PaymentGateway $gateway,
    ) {
    }

    /**
     * @param  array{guest_name: string, guest_phone?: string|null, special_requests?: string|null, gst_number?: string|null, business_name?: string|null}  $guest
     *
     * @throws \RuntimeException with a customer-facing reason
     */
    public function book(User $customer, Accommodation $hotel, HotelRoomType $room, HotelRatePlan $plan, string $checkIn, string $checkOut, int $rooms, int $adults, array $guest, string $method, ?string $couponCode = null, int $children = 0): HotelReservation
    {
        $scope = HotelPricing::scope($hotel);
        // Hotels switched off for this area: say so first (the engine checks again inside its transaction).
        $franchise = $hotel->franchise;
        if ($franchise && ! app(\App\Services\ModuleActivationService::class)->isActive(\App\Support\Modules::HOTEL, [
            'zone_id' => $hotel->zone_id, 'franchise_id' => $franchise->id, 'city_id' => $franchise->city_id, 'country_id' => $franchise->country_id,
        ])) {
            throw new \App\Exceptions\ModuleNotActiveException(\App\Support\Modules::HOTEL);
        }
        if (! Accommodation::bookable()->whereKey($hotel->id)->exists() || $room->accommodation_id !== $hotel->id || $plan->hotel_room_type_id !== $room->id || ! $room->is_active || ! $plan->is_active) {
            throw new \RuntimeException('This room cannot be booked right now.');
        }
        if (! in_array($method, ['online', 'wallet'], true)) {
            throw new \RuntimeException('Choose how to pay.');
        }
        // Never a past check-in, never past the booking window (the hotel's local date). The pages clean dates in the
        // query too; this is the check every caller (website, app API) goes through.
        $today = Carbon::parse(app(\App\Services\TimezoneResolver::class)->format(now(), $hotel->franchise, 'Y-m-d'));
        $in = Carbon::parse($checkIn)->startOfDay();
        if ($in->lt($today) || $in->gt($today->copy()->addDays((int) HotelSettings::get('hotel.booking_window_days', $scope)))) {
            throw new \RuntimeException('Choose a check-in date from today up to '.HotelSettings::get('hotel.booking_window_days', $scope).' days ahead.');
        }
        $nights = Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
        if ($nights < 1 || $nights > (int) HotelSettings::get('hotel.max_nights', $scope)) {
            throw new \RuntimeException('Choose a stay of 1 to '.HotelSettings::get('hotel.max_nights', $scope).' nights.');
        }
        if ($rooms < 1 || $rooms > (int) HotelSettings::get('hotel.max_rooms_per_booking', $scope)) {
            throw new \RuntimeException('Too many rooms for one booking.');
        }
        if ($adults > $room->max_adults * $rooms) {
            throw new \RuntimeException("These rooms fit at most {$room->max_adults} adults each. Add a room.");
        }
        if ($children < 0 || $children > (int) $room->max_children * $rooms) {
            throw new \RuntimeException((int) $room->max_children === 0
                ? 'This room does not take children. Choose another room.'
                : "These rooms fit at most {$room->max_children} ".\Illuminate\Support\Str::plural('child', (int) $room->max_children).' each. Add a room.');
        }

        $quote = HotelPricing::quote($room, $plan, $checkIn, $checkOut, $rooms);
        if (! $quote['taxes_known']) {
            throw new \RuntimeException('Booking opens soon for this hotel.');
        }
        if (! $quote['available']) {
            throw new \RuntimeException('These rooms were just taken for your dates. Please choose another room or date.');
        }

        // Coupon (step 2): online or wallet only (the engine refuses anything else), checked now and again under the
        // coupon lock in the same transaction that takes the rooms.
        $couponCode = trim((string) $couponCode);
        $ctx = null;
        if ($couponCode !== '') {
            $ctx = $this->couponContext($hotel, $customer, $method, $couponCode, $quote['room_total']);
            $pre = app(CouponService::class)->validate($ctx);
            if (! $pre->eligible) {
                throw new \RuntimeException($pre->message ?: PromotionResult::GENERIC_MESSAGE);
            }
            $quote = HotelPricing::withDiscount($quote, $pre->discountTotal);
        }

        $reservation = DB::transaction(function () use ($hotel, $customer, $room, $plan, $checkIn, $checkOut, $rooms, $adults, $children, $guest, $method, $quote, $scope, $ctx, $couponCode) {
            $reservation = $this->create->execute([
                'franchise_id' => $hotel->franchise_id,
                'zone_id' => $hotel->zone_id,
                'accommodation_id' => $hotel->id,
                'customer_id' => $customer->id,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'rooms' => [['room_type_id' => $room->id, 'rate_plan_id' => $plan->id, 'room_count' => $rooms]],
                'number_of_adults' => $adults,
                'guests' => [['name' => trim($guest['guest_name']), 'phone' => $guest['guest_phone'] ?? null, 'is_primary' => true, 'guest_type' => 'adult']],
                'special_requests' => filled($guest['special_requests'] ?? null) ? trim($guest['special_requests']) : null,
                'payment_method' => $method,
                'tax_amount' => $quote['gst'],
                'convenience_fee' => $quote['fee'],
                'amount_due' => $quote['total'],
                'payment_expires_at' => $method === 'online' ? now()->addMinutes((int) HotelSettings::get('hotel.unpaid_hold_minutes', $scope)) : null,
                'guest_gst_number' => filled($guest['gst_number'] ?? null) ? strtoupper(trim($guest['gst_number'])) : null,
                'guest_business_name' => filled($guest['business_name'] ?? null) ? trim($guest['business_name']) : null,
            ]);

            // The quote's room price is the one the customer saw (weekend price included); the engine's own quote knows
            // only the calendar and normal price, so align it. price_quoted stays before the coupon (commission base).
            $reservation->forceFill([
                'price_quoted' => $quote['room_total'],
                'number_of_children' => $children,
                'coupon_code' => $ctx ? strtoupper($couponCode) : null,
                'coupon_discount_amount' => $quote['discount'] ?? 0,
            ])->save();

            if ($ctx) {
                [$result, $usage] = app(CouponService::class)->reserve($ctx);
                if (round(min($result->discountTotal, $quote['room_total']), 2) !== round((float) $quote['discount'], 2)) {
                    throw new \RuntimeException('This coupon changed while you were booking. Please try again.');
                }
                $usage->forceFill(['hotel_reservation_id' => $reservation->id])->save();
            }

            return $reservation;
        });

        if ($method === 'wallet') {
            $this->afterPaid($reservation->fresh());
        }

        return $reservation->fresh();
    }

    /** What a coupon would take off this stay right now: [discount, message]. Nothing is reserved. */
    public function previewCoupon(User $customer, Accommodation $hotel, float $roomTotal, string $method, string $code): array
    {
        $result = app(CouponService::class)->validate($this->couponContext($hotel, $customer, $method, trim($code), $roomTotal));

        return $result->eligible
            ? [round(min($result->discountTotal, $roomTotal), 2), null]
            : [0.0, $result->message ?: PromotionResult::GENERIC_MESSAGE];
    }

    /** The coupon engine's view of a stay: one line, the room price (no service targets ever match). */
    private function couponContext(Accommodation $hotel, User $customer, string $method, string $code, float $roomTotal): PromotionContext
    {
        $hotel->loadMissing('franchise');

        return new PromotionContext(
            module: Modules::HOTEL,
            franchiseId: $hotel->franchise_id,
            cityId: $hotel->franchise?->city_id,
            zoneId: $hotel->zone_id,
            customer: $customer,
            paymentMethod: $method,
            lines: [['line_ref' => 'h0', 'line_total' => round($roomTotal, 2), 'flash_applied' => false, 'entitlement_covered' => false]],
            code: $code,
            countryId: $hotel->franchise?->country_id,
        );
    }

    /** @return array{payment_id: int, razorpay_order_id: string, razorpay_key_id: ?string, amount: int, currency: string} */
    public function startOnlinePayment(HotelReservation $reservation): array
    {
        if (! $reservation->isAwaitingOnlinePayment() || ($reservation->payment_expires_at && $reservation->payment_expires_at->isPast())) {
            throw new \RuntimeException('This booking can no longer be paid. Please book again.');
        }
        if (! $this->gateway->isConfigured()) {
            throw new \RuntimeException('Online payment is not available right now.');
        }
        if (Setting::get('payment.online_enabled', '1', array_filter(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id])) !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        $order = $this->gateway->createRawOrder(
            $reservation->amountDue(),
            'hotel-'.$reservation->id.'-'.now()->format('His'),
            ['hotel_reservation_id' => $reservation->id, 'purpose' => 'hotel_reservation'],
        );
        $payment = Payment::create([
            'hotel_reservation_id' => $reservation->id,
            'purpose' => 'hotel_reservation',
            'amount' => $reservation->amountDue(),
            'gateway' => $this->gateway->identifier(),
            'gateway_order_id' => $order['razorpay_order_id'],
            'status' => 'pending',
        ]);

        return [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $order['razorpay_order_id'],
            'razorpay_key_id' => $order['key_id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
        ];
    }

    /**
     * Webhook capture (RazorpayWebhookHandler, after its amount check and pending→captured lock: runs once per
     * payment). Paid and confirmed — or, if the booking was cancelled / expired meanwhile or is already paid, the
     * money goes straight back in full.
     */
    public function markPaid(Payment $payment): void
    {
        $reservation = $payment->hotelReservation;
        if (! $reservation) {
            return;
        }

        $outcome = DB::transaction(function () use ($reservation) {
            $locked = HotelReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'cancelled') {
                return 'cancelled';
            }
            if ($locked->payment_status === 'paid') {
                return 'already_paid';
            }
            $locked->forceFill(['payment_status' => 'paid', 'payment_expires_at' => null])->save();

            return 'paid';
        });

        if ($outcome === 'paid') {
            $this->afterPaid($reservation->fresh());

            return;
        }

        $this->refundInFull($reservation->fresh(), $payment, $outcome === 'cancelled' ? 'booking was cancelled before the payment arrived' : 'paid twice');
    }

    /** Paid: confirmed at once with a check-in code, or waiting for the hotel to accept (HotelStayService, H3). */
    private function afterPaid(HotelReservation $reservation): void
    {
        $reservation->statusHistory()->create(['status' => $reservation->status, 'note' => 'Paid ('.$reservation->payment_method.')', 'changed_at' => now()]);
        app(HotelStayService::class)->afterPaid($reservation);
    }

    private function refundInFull(HotelReservation $reservation, Payment $payment, string $why): void
    {
        if ($payment->status !== 'captured' || (float) $payment->refunded_amount > 0) {
            return;
        }
        $this->gateway->refund($payment->gateway_payment_id, (float) $payment->amount, "Hotel booking {$reservation->code}: {$why}");
        $payment->forceFill(['refunded_amount' => $payment->amount, 'status' => 'refunded'])->save();
    }

    /**
     * hotels:expire-unpaid — online bookings not paid within the hold are cancelled and their rooms freed. A payment
     * that still arrives later is refunded by markPaid().
     */
    public function expireUnpaid(): int
    {
        $count = 0;
        HotelReservation::query()->where('status', 'pending')->where('payment_status', 'pending')->where('payment_method', 'online')
            ->whereNotNull('payment_expires_at')->where('payment_expires_at', '<', now())
            ->orderBy('id')->limit(200)->pluck('id')
            ->each(function (int $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $r = HotelReservation::with('rooms.roomType')->lockForUpdate()->find($id);
                    if (! $r || ! $r->isAwaitingOnlinePayment() || ! $r->payment_expires_at?->isPast()) {
                        return;
                    }
                    $r->forceFill(['status' => 'cancelled', 'cancellation_note' => 'Not paid in time', 'cancellation_fee' => 0])->save();
                    foreach ($r->rooms as $line) {
                        app(HotelAvailabilityService::class)->releaseRooms($line->roomType, $r->check_in_date->toDateString(), $r->check_out_date->toDateString(), $line->room_count);
                    }
                    $r->statusHistory()->create(['status' => 'cancelled', 'note' => 'Not paid in time; rooms released', 'changed_at' => now()]);
                    $count++;
                });
            });

        return $count;
    }
}
