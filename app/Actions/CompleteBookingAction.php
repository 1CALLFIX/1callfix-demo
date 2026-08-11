<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use App\Services\LoyaltyService;
use App\Services\ReferralService;
use Illuminate\Support\Facades\DB;

class CompleteBookingAction
{
    public function __construct(
        private CommissionService $commissionService,
        private LoyaltyService $loyaltyService,
        private ReferralService $referralService,
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

            if (empty($booking->completion_otp) || $booking->completion_otp !== $enteredOtp) {
                throw new \RuntimeException('Incorrect completion OTP.');
            }

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

        // Commission split runs after the completion transaction commits —
        // deliberately outside the lock above, since it does its own
        // transaction and wallet crediting, and we don't want to hold the
        // booking row lock any longer than necessary.
        $this->commissionService->applyForBooking($booking);

        $scope = array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);

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

        if ($booking->customer) {
            $channels = ChannelResolver::resolve($scope);
            $booking->customer->notify(new BookingStatusNotification('completed', $booking, $channels));
        }

        return $booking;
    }
}
