<?php

namespace App\Actions;

use App\Models\BookingExtraItem;
use Illuminate\Support\Facades\DB;

class RespondToExtraWorkAction
{
    /**
     * Customer approves or rejects a specific extra-work item. On either
     * response, the booking resumes from hold — approved just means the
     * amount will be included at completion, rejected means it won't, but
     * the job itself continues either way (rejecting extra work doesn't
     * cancel the original booking).
     *
     * @throws \RuntimeException if the item isn't awaiting a response, or
     *         the customer doesn't own this booking
     */
    public function execute(int $itemId, int $customerId, bool $approved): BookingExtraItem
    {
        return DB::transaction(function () use ($itemId, $customerId, $approved) {
            $item = BookingExtraItem::lockForUpdate()->findOrFail($itemId);
            $booking = $item->booking;

            if ($booking->customer_id !== $customerId) {
                throw new \RuntimeException('This is not your booking.');
            }

            if ($item->status !== 'pending_approval') {
                throw new \RuntimeException('This extra work item has already been responded to.');
            }

            $item->status = $approved ? 'approved' : 'rejected';
            $item->responded_at = now();
            $item->save();

            $note = $approved
                ? "Extra work approved: {$item->description} (₹{$item->amount})"
                : "Extra work declined: {$item->description}";

            (new ResumeBookingAction())->execute($booking->id, $note);

            return $item;
        });
    }
}
