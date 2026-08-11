<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

class AdminCancelBookingAction
{
    public function __construct(private CancellationService $cancellationService)
    {
    }

    public function execute(int $bookingId, string $reason): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $reason) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (in_array($booking->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Booking is already {$booking->status}, cannot cancel.");
            }

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

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new BookingStatusNotification('cancelled', $booking, $channels));
        }

        return $booking->fresh();
    }
}
