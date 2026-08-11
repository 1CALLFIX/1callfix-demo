<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use App\Services\Plans\EntitlementService;
use Illuminate\Support\Facades\DB;

class AdminCancelBookingAction
{
    /** Statuses that count as "before work began" — same boundary CompleteBookingAction's completable-from set implicitly treats as post-service. Used to decide entitlement reversal (approved plan §7). */
    private const PRE_SERVICE_STATUSES = ['pending', 'searching_provider', 'assigned', 'provider_en_route'];

    public function __construct(
        private CancellationService $cancellationService,
        private EntitlementService $entitlementService,
    ) {
    }

    public function execute(int $bookingId, string $reason): Booking
    {
        $statusBeforeCancel = null;

        $booking = DB::transaction(function () use ($bookingId, $reason, &$statusBeforeCancel) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (in_array($booking->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Booking is already {$booking->status}, cannot cancel.");
            }

            $statusBeforeCancel = $booking->status;

            $fee = $this->cancellationService->calculateFee($booking);

            $booking->status = 'cancelled';
            $booking->cancellation_note = $reason;
            $booking->cancellation_fee = $fee;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        // Refund runs after the cancellation transaction commits — same
        // pattern CompleteBookingAction uses for CommissionService: its own
        // transaction, doesn't hold the booking row lock during an external
        // Razorpay API call.
        $this->cancellationService->refundIfPaid($booking, (float) $booking->cancellation_fee);

        // Plan Engine: reverse any customer-side entitlement consumed at
        // booking_created, but ONLY for a pre-service cancellation — the
        // same status boundary this action already recognizes as "before
        // work began" (approved plan §7). Cancelling after in_progress/
        // on_hold never auto-reverses; a support agent can still adjust()
        // manually. No-op if nothing was ever consumed for this booking.
        if (in_array($statusBeforeCancel, self::PRE_SERVICE_STATUSES, true)) {
            $this->entitlementService->reverseForCancelledBooking($booking);
        }

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new BookingStatusNotification('cancelled', $booking, $channels));
        }

        return $booking->fresh();
    }
}
