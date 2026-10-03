<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Notifications\BookingStatusNotification;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use App\Services\Plans\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminCancelBookingAction
{
    /** Statuses that count as "before work began" — same boundary CompleteBookingAction's completable-from set implicitly treats as post-service. Used to decide entitlement reversal (approved plan §7). */
    private const PRE_SERVICE_STATUSES = ['pending', 'searching_provider', 'assigned', 'provider_en_route'];

    public function __construct(
        private CancellationService $cancellationService,
        private EntitlementService $entitlementService,
    ) {
    }

    /**
     * @param  bool  $reconcileBundle  Phase E5.1 — when this booking is a
     *        bundle child, also reconcile the ONE shared bundle Payment and
     *        advance the bundle status latch (BundleSettlementService).
     *        Passed `false` only by CancelBookingBundleAction, which cancels
     *        every child in a loop and reconciles once at the end.
     * @param  ?string  $customerNotificationEvent  REF 1CF-IMPLEMENT-20260922-L01
     *        — which BookingStatusNotification event key to send the
     *        customer, instead of the default 'cancelled'. Every other
     *        caller (admin's own cancel button, the customer's self-cancel,
     *        QaSeeder) leaves this null and gets the unchanged 'cancelled'
     *        copy. DispatchDeadlineSweepService passes 'no_provider_found'
     *        so a platform auto-cancellation doesn't read, misleadingly, as
     *        something the customer or an admin did — without duplicating
     *        this method's refund/entitlement/notification logic anywhere
     *        else.
     */
    /**
     * @param  bool  $creditToMainWallet  REF 1CF-IMPLEMENT-20260923-MAIN-WALLET
     *        — threaded straight through to CancellationService::
     *        refundIfPaid()'s own param of the same name; see its docblock.
     *        Passed true only by DispatchDeadlineSweepService.
     */
    public function execute(int $bookingId, string $reason, bool $reconcileBundle = true, ?string $customerNotificationEvent = null, bool $creditToMainWallet = false, bool $waiveFee = false, ?callable $feeResolver = null, string $cancelledByRole = 'admin'): Booking
    {
        $statusBeforeCancel = null;

        $booking = DB::transaction(function () use ($bookingId, $reason, $waiveFee, $feeResolver, $cancelledByRole, &$statusBeforeCancel) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (in_array($booking->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Booking is already {$booking->status}, cannot cancel.");
            }

            $statusBeforeCancel = $booking->status;

            // REF 1CF-JOURNEY-001 — a cancellation that is the platform's / the professional's fault carries no
            // fee: explicitly waived by the operator, or implied by a provider-side hold ("professional left").
            $basis = null;
            if ($feeResolver !== null) {
                // REF 1CF-CANCEL-POLICY-001 — the customer path re-checks eligibility and prices the charge HERE, under
                // the same row lock that performs the cancel, so a racing resume/hold can never slip between check and write.
                [$fee, $basis] = $feeResolver($booking);
            } else {
                $fee = ($waiveFee || $booking->hold_category === 'provider_side')
                    ? 0.0
                    : $this->cancellationService->calculateFee($booking);
            }

            $booking->status = 'cancelled';
            $booking->cancellation_note = $reason;
            $booking->cancellation_fee = $fee;
            $booking->cancelled_by_role = $cancelledByRole;
            $booking->cancellation_fee_basis = $basis;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by {$cancelledByRole}: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        // Refund runs after the cancellation transaction commits — same
        // pattern CompleteBookingAction uses for CommissionService: its own
        // transaction, doesn't hold the booking row lock during an external
        // Razorpay API call.
        $this->cancellationService->refundIfPaid($booking, (float) $booking->cancellation_fee, $creditToMainWallet);

        // Plan Engine: reverse any customer-side entitlement consumed at
        // booking_created, but ONLY for a pre-service cancellation — the
        // same status boundary this action already recognizes as "before
        // work began" (approved plan §7). Cancelling after in_progress/
        // on_hold never auto-reverses; a support agent can still adjust()
        // manually. No-op if nothing was ever consumed for this booking.
        if (in_array($statusBeforeCancel, self::PRE_SERVICE_STATUSES, true)) {
            $this->entitlementService->reverseForCancelledBooking($booking);
        }

        // Free Service Visit: a unit is used ONLY here — a no-work cancellation after the professional's verified
        // arrival whose visit charge the waiver forgave. Never on creation or completion (thumb rule, CLAUDE.md).
        app(\App\Services\Cancellation\PrimeWaiver::class)->consumeForNoWorkVisit($booking);

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new BookingStatusNotification($customerNotificationEvent ?? 'cancelled', $booking, $channels));
        }

        // Phase PN1 — tell the assigned provider their job was cancelled out
        // from under them. Only when there IS a provider (a pre-assignment
        // cancellation has none). Guarded + logged, post-commit.
        if ($booking->provider?->user) {
            try {
                $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
                $booking->provider->user->notify(new ProviderJobStatusNotification('cancelled', $booking, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver provider 'cancelled' notification for booking [{$booking->id}]: ".$e->getMessage());
            }
        }

        // Phase E5.1 — a cancelled bundle child reconciles the ONE shared
        // bundle Payment (the per-child refundIfPaid above is inert for a
        // bundle child, which has no Payment of its own) and advances the
        // bundle status latch. Skipped when CancelBookingBundleAction is
        // cancelling every child and will reconcile once itself.
        if ($booking->booking_bundle_id && $reconcileBundle) {
            app(\App\Services\BundleSettlementService::class)->settleFromChildren($booking->booking_bundle_id, $booking->id);
        }

        return $booking->fresh();
    }
}
