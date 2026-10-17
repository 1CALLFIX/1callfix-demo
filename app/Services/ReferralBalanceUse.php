<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Support\EarningsSettings;

/**
 * Owner decision 2026-10-17 — paying part of a booking from the referral balance.
 *
 *   - Only on an ONLINE booking: the rest is paid by Razorpay. Never with cash, and never combined with
 *     the customer's own wallet (THUMB RULE: a booking with a benefit is paid online).
 *   - At most referral.balance_max_use_percent % of what the booking costs (after any coupon).
 *     Blank or 0 = the referral balance cannot be spent.
 *   - Taken when the booking is created. If the booking is cancelled — by anyone, at any stage — the
 *     whole referral part goes back to the referral balance (never to the wallet or the bank); a
 *     cancellation charge is taken from the online-paid part only.
 */
class ReferralBalanceUse
{
    public function __construct(private ReferralBalanceService $balances)
    {
    }

    /** How much referral balance this booking could use right now (0 = none). */
    public function usableFor(Booking $booking): float
    {
        $percent = EarningsSettings::number('referral.balance_max_use_percent');
        if ($percent === null || $percent <= 0 || ! $booking->customer) {
            return 0.0;
        }

        $cap = floor($booking->amountPayable() * min($percent, 100)) / 100;

        return round(min($cap, $this->balances->available($booking->customer)), 2);
    }

    /** Apply the referral part to a just-created booking. Call inside the booking's DB transaction. */
    public function apply(Booking $booking): float
    {
        if ($booking->payment_method !== 'online') {
            throw new \RuntimeException('Referral balance can only be used with online payment; the rest is paid online.');
        }

        $part = $this->usableFor($booking);
        if ($part <= 0) {
            throw new \RuntimeException('No referral balance can be used on this booking.');
        }

        $this->balances->debit($booking->customer, $part, "Part payment for booking {$booking->code}", "booking:{$booking->id}:referral-balance", $booking->id);

        $booking->forceFill(['referral_balance_used' => $part])->save();

        // The admin allowed 100% and the balance covered everything: nothing is left for Razorpay.
        if ($booking->amountPayable() <= 0) {
            Payment::create([
                'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 0,
                'gateway' => 'referral_balance', 'status' => 'captured', 'captured_at' => now(),
            ]);
            $booking->forceFill(['payment_status' => 'paid'])->save();
        }

        return $part;
    }

    /** A cancelled booking returns its referral part to the referral balance, once. */
    public function returnOnCancel(Booking $booking): void
    {
        $used = (float) $booking->referral_balance_used;
        if ($used <= 0 || ! $booking->customer) {
            return;
        }

        $this->balances->credit($booking->customer, $used, "Returned: booking {$booking->code} was cancelled", "booking:{$booking->id}:referral-balance-return", $booking->id);
    }
}
