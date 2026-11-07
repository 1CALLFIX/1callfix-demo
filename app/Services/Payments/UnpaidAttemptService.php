<?php

namespace App\Services\Payments;

use App\Actions\AdminCancelBookingAction;
use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owner rule 2026-11-07 — NO PAYMENT, NO BOOKING (service bookings).
 *
 * An online booking is only a payment attempt until Razorpay captures its payment (Booking::isPaymentAttempt()).
 * This service ends attempts that never became bookings, quietly (no "cancelled" message: for the customer there
 * was never a booking):
 *   - expire(): attempts older than `payments.unpaid_attempt_minutes` (Super Admin, default 15);
 *   - replaceFor(): when the same customer starts a new booking, their earlier open attempts end, so going back
 *     from the payment window and booking again (online or cash) never leaves two.
 * A payment that still arrives afterwards is refunded by RazorpayWebhookHandler (booking already cancelled ->
 * refundIfPaid), the existing late-capture path.
 *
 * Services cart (bundles): an unpaid online bundle ends the same way — every child cancelled quietly with no fee; a
 * late payment is refunded in full by RazorpayWebhookHandler (cancelled bundle -> BundleSettlementService).
 */
class UnpaidAttemptService
{
    public const MINUTES = 'payments.unpaid_attempt_minutes';
    public const DEFAULT_MINUTES = 15;

    public function __construct(private AdminCancelBookingAction $cancelAction)
    {
    }

    public static function minutes(): int
    {
        $v = Setting::get(self::MINUTES);

        return is_numeric($v) && (int) $v >= 5 && (int) $v <= 120 ? (int) $v : self::DEFAULT_MINUTES;
    }

    /** When this attempt stops being payable, or null when it is not an open attempt. */
    public static function expiresAt(Booking $booking): ?Carbon
    {
        if (! $booking->isPaymentAttempt() || $booking->status !== 'pending' || $booking->booking_bundle_id !== null) {
            return null;
        }

        return $booking->created_at?->copy()->addMinutes(self::minutes());
    }

    /** @return int attempts ended because they ran out of time */
    public function expire(): int
    {
        $cutoff = now()->subMinutes(self::minutes());
        $ids = $this->openAttempts()->where('created_at', '<=', $cutoff)->pluck('id');
        $ended = $ids->sum(fn ($id) => $this->end((int) $id, 'Online payment not completed in time — no booking was made.') ? 1 : 0);

        foreach ($this->openBundles()->where('created_at', '<=', $cutoff)->pluck('id') as $bundleId) {
            $ended += $this->endBundle((int) $bundleId, 'Online payment not completed in time — no booking was made.');
        }

        return $ended;
    }

    /** @return int attempts ended because the customer started a new booking */
    public function replaceFor(int $customerId): int
    {
        $ids = $this->openAttempts()->where('customer_id', $customerId)->pluck('id');

        $reason = 'Replaced by the customer\'s new booking before payment — no booking was made.';
        $ended = $ids->sum(fn ($id) => $this->end((int) $id, $reason) ? 1 : 0);

        foreach ($this->openBundles()->where('customer_id', $customerId)->pluck('id') as $bundleId) {
            $ended += $this->endBundle((int) $bundleId, $reason);
        }

        return $ended;
    }

    /** Ends one open attempt now (the customer's own "Book with cash instead" / "Cancel"). */
    public function endNow(Booking $booking, string $reason): bool
    {
        return $this->end($booking->id, $reason);
    }

    private function openAttempts()
    {
        return Booking::query()
            ->where('payment_method', 'online')
            ->where('payment_status', 'pending')
            ->where('status', 'pending')
            ->whereNull('booking_bundle_id');
    }

    private function openBundles()
    {
        return \App\Models\BookingBundle::query()
            ->where('payment_method', 'online')
            ->where('payment_status', 'pending')
            ->where('status', 'active');
    }

    /** Ends an unpaid online bundle: each open child quietly, no fee; the bundle then latches to cancelled. */
    private function endBundle(int $bundleId, string $reason): int
    {
        $ended = 0;
        foreach (Booking::where('booking_bundle_id', $bundleId)->whereNotIn('status', ['completed', 'cancelled'])->pluck('id') as $id) {
            try {
                $this->cancelAction->execute((int) $id, $reason, waiveFee: true, cancelledByRole: 'system', notifyCustomer: false);
                $ended++;
            } catch (\Throwable $e) {
                Log::error("UnpaidAttemptService: could not end unpaid bundle child [{$id}]: ".$e->getMessage());
            }
        }

        return $ended > 0 ? 1 : 0;
    }

    private function end(int $id, string $reason): bool
    {
        $still = DB::transaction(function () use ($id) {
            $b = Booking::lockForUpdate()->find($id);

            return $b && $b->isPaymentAttempt() && $b->status === 'pending' && $b->booking_bundle_id === null;
        });
        if (! $still) {
            return false;
        }

        try {
            $this->cancelAction->execute($id, $reason, waiveFee: true, cancelledByRole: 'system', notifyCustomer: false);
        } catch (\Throwable $e) {
            Log::error("UnpaidAttemptService: could not end payment attempt [{$id}]: ".$e->getMessage());

            return false;
        }

        return true;
    }
}
