<?php

namespace App\Support\Journey;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Services\TimezoneResolver;

/**
 * REF 1CF-JOURNEY-001 — builds the JourneyBuilder context for a service booking: the schedule, who
 * the professional is, how it is being paid and, for a cancellation, the reason / fee / refund in
 * words. Read-only; every value comes from columns the booking and payment flows already write.
 */
final class JourneyContext
{
    /** @return array<string,mixed> */
    public static function forBooking(Booking $booking): array
    {
        $booking->loadMissing(['franchise.country', 'provider.user', 'payment']);

        $tz = app(TimezoneResolver::class);
        $symbol = (string) Setting::get('locale.currency_symbol', '₹');
        $money = fn ($v) => $symbol.number_format((float) $v, 2);

        $context = [
            'provider_name' => $booking->provider?->user?->name,
            'payment' => [
                'status' => $booking->payment_status,
                'method' => $booking->payment_method,
                'amount_label' => $money($booking->price_final ?? $booking->price_quoted),
            ],
        ];

        if ($booking->scheduled_at) {
            $context['scheduled_label'] = $tz->format($booking->scheduled_at, $booking->franchise, 'D j M, g:i A');
        }

        if ($booking->status === 'cancelled') {
            $fee = (float) ($booking->cancellation_fee ?? 0);
            $payment = $booking->payment;
            $refunded = (float) ($payment?->refunded_amount ?? 0);

            $refundLabel = null;
            if ($refunded > 0 || $booking->payment_status === 'refunded') {
                $toWallet = WalletTransaction::where('ref', "booking:{$booking->id}:wallet-refund")->exists();
                $amount = $refunded > 0 ? $money($refunded) : 'Your payment';
                $refundLabel = $toWallet
                    ? "{$amount} refunded to your 1CallFix wallet."
                    : "{$amount} refunded to your original payment method (usually within 3–5 working days).";
            }

            $context['cancel'] = [
                'reason' => $booking->cancellation_note,
                'fee_label' => $fee > 0 ? $money($fee) : null,
                'refund_label' => $refundLabel,
            ];
        }

        return $context;
    }
}
