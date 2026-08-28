<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Exceptions\BookingOtpException;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\BookingOtpService;
use App\Services\CommissionService;
use App\Services\CompensationService;
use App\Services\Documents\DocumentService;
use App\Services\LoyaltyService;
use App\Services\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteBookingAction
{
    public function __construct(
        private CommissionService $commissionService,
        private LoyaltyService $loyaltyService,
        private ReferralService $referralService,
        private CompensationService $compensationService,
        private BookingOtpService $bookingOtp,
        private DocumentService $documents,
    ) {
    }

    /**
     * Provider marks the job complete, entering the OTP the customer read
     * out to them (start_otp confirms the provider actually arrived and
     * began work — this uses a separate completion_otp shown to the
     * customer once the provider signals they're done, so the customer
     * confirms the work actually happened before it's marked complete).
     *
     * On success: status -> completed, commission split calculated and
     * applied, provider's wallet credited.
     *
     * @throws \RuntimeException if the OTP is wrong or the booking isn't
     *         in a completable state
     */
    public function execute(int $bookingId, Provider $provider, string $enteredOtp): Booking
    {
        try {
            $booking = DB::transaction(function () use ($bookingId, $provider, $enteredOtp) {
                $booking = Booking::lockForUpdate()->findOrFail($bookingId);

                if ($booking->provider_id !== $provider->id) {
                    throw new \RuntimeException('This booking is not assigned to you.');
                }

                if (!in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true)) {
                    throw new \RuntimeException(
                        "Booking [{$bookingId}] cannot be completed from status '{$booking->status}'."
                    );
                }

                // Phase E5 — provider-ownership gate and status gate first
                // (both above), then the hardened verify: expiry, attempt cap
                // and single-use, keeping the same plain-string compare and
                // the same "wrong code -> RuntimeException, booking NOT
                // advanced" contract. On success the code is consumed inside
                // this transaction; on a wrong code it throws and the counter
                // increment is committed separately in the catch below,
                // surviving this transaction's rollback.
                $this->bookingOtp->verifyOrFail($booking, 'completion', $enteredOtp);

                $approvedExtras = $booking->extraItems()
                    ->where('status', 'approved')
                    ->sum('amount');

                $booking->status = 'completed';
                $booking->price_final = $booking->price_quoted + $approvedExtras;
                $booking->completed_at = now();
                $booking->save();

                $booking->statusHistory()->create([
                    'status' => 'completed',
                    'changed_by' => $provider->user_id,
                    'note' => 'Completed with verified OTP',
                    'changed_at' => now(),
                ]);

                $provider->increment('jobs_completed');

                event(new BookingStatusUpdated($booking));

                return $booking->fresh();
            });
        } catch (BookingOtpException $e) {
            if ($e->countsAsAttempt) {
                $this->bookingOtp->registerFailedAttempt($bookingId, 'completion');
            }
            throw $e;
        }

        $scope = array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);

        // Auto-computed compensation (overtime/night/peak) — same
        // "outside the lock, own transaction, idempotent" placement as
        // commission below. No-ops entirely while every rate stays at its
        // Setting-driven default of 0.
        $this->compensationService->applyAutomaticForBooking($booking, $scope);

        // Commission split runs after the completion transaction commits —
        // deliberately outside the lock above, since it does its own
        // transaction and wallet crediting, and we don't want to hold the
        // booking row lock any longer than necessary.
        $this->commissionService->applyForBooking($booking);

        // Loyalty points: customer earns per rupee spent, provider earns a
        // flat amount per completed job -- same "outside the lock, its own
        // transaction" placement as commission above. Both are idempotent
        // per (user, booking, reason) — see LoyaltyService::earn().
        $customerRate = (float) Setting::get('loyalty.customer_points_per_currency_unit', '0.01', $scope);
        $customerPoints = (int) floor((float) $booking->price_final * $customerRate);
        if ($booking->customer) {
            $this->loyaltyService->earn($booking->customer, $customerPoints, 'booking_completed', $booking, $scope);
        }

        $providerPoints = (int) Setting::get('loyalty.provider_points_per_completed_job', '5', $scope);
        if ($booking->provider && $booking->provider->user) {
            $this->loyaltyService->earn($booking->provider->user, $providerPoints, 'booking_completed', $booking, $scope);
        }

        // Referral qualification: does this booking make the customer's
        // referral "count" (their first-ever completed booking)?
        $this->referralService->qualifyFromCompletedBooking($booking, $scope);

        // Phase E5 — materialise the receipt for this completed booking
        // through the EXISTING DocumentService, which numbers it via
        // DocumentNumberService. That numbering is idempotent on
        // (documentable, type), so a duplicate completion (already blocked
        // by the status gate) or a retried job produces no second document.
        // The amount is read from the captured Payment, never recomputed
        // here. A bundle child has no Payment of its own (Phase E3 keeps ONE
        // Payment per bundle), so it falls back to the bundle's aggregate
        // Payment. Post-commit and guarded like the notification sends: a
        // numbering hiccup must not roll back an already-completed, already-
        // settled job — the on-demand DocumentController path is still there.
        try {
            $payment = $booking->payment()->where('status', 'captured')->latest('id')->first()
                ?? $booking->bundle?->payment()->where('status', 'captured')->latest('id')->first();

            if ($payment) {
                $this->documents->forPayment($payment, 'receipt');
            }
        } catch (\Throwable $e) {
            Log::error("Phase E5: failed to generate the completion receipt for booking [{$booking->id}]: ".$e->getMessage());
        }

        if ($booking->customer) {
            $channels = ChannelResolver::resolve($scope);
            $booking->customer->notify(new BookingStatusNotification('completed', $booking, $channels));
        }

        // Phase E5.1 — if this is a bundle child, advance the bundle's stored
        // status latch now that this child is terminal (its own locked
        // transaction, post-commit, idempotent — same placement as the
        // commission / loyalty / receipt side effects above). A completion
        // never triggers a refund: settleFromChildren only refunds children
        // that were cancelled.
        if ($booking->booking_bundle_id) {
            try {
                app(\App\Services\BundleSettlementService::class)->settleFromChildren($booking->booking_bundle_id);
            } catch (\Throwable $e) {
                Log::error("Phase E5.1: bundle status latch after completing booking [{$booking->id}] failed: ".$e->getMessage());
            }
        }

        return $booking;
    }
}
