<?php

namespace App\Actions;

use App\Models\BookingExtraItem;
use App\Models\Setting;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RespondToExtraWorkAction
{
    /**
     * Customer approves or rejects a specific extra-work item. On either response, the booking resumes from
     * hold — approved just means the amount will be included at completion, rejected means it won't, but the
     * job itself continues either way (rejecting extra work doesn't cancel the original booking).
     *
     * REF 1CF-EXTRAWORK-001: if an operator has already resumed the job in the meantime the answer is still
     * recorded (and the provider told) but nothing is "resumed" twice; and the provider is notified.
     *
     * @throws \RuntimeException if the item isn't awaiting a response, or the customer doesn't own this booking
     */
    public function execute(int $itemId, int $customerId, bool $approved): BookingExtraItem
    {
        $item = DB::transaction(function () use ($itemId, $customerId, $approved) {
            $item = BookingExtraItem::lockForUpdate()->findOrFail($itemId);
            $booking = $item->booking;

            if ($booking->customer_id !== $customerId) {
                throw new \RuntimeException('This is not your booking.');
            }

            if ($item->status !== 'pending_approval') {
                throw new \RuntimeException('This extra work item has already been responded to.');
            }

            if (! in_array($booking->status, ['on_hold', 'in_progress'], true)) {
                throw new \RuntimeException('This job is no longer active, so the extra work request has lapsed.');
            }

            $item->status = $approved ? 'approved' : 'rejected';
            $item->responded_at = now();
            $item->save();

            $note = $approved
                ? 'Extra work approved: '.$item->description.' ('.Setting::get('locale.currency_symbol', '₹').$item->amount.')'
                : "Extra work declined: {$item->description}";

            // Resume only if this request is what is holding the job.
            if ($booking->status === 'on_hold' && $booking->hold_reason === 'awaiting_customer_approval') {
                (new ResumeBookingAction())->execute($booking->id, $note);
            }

            return $item;
        });

        $this->notifyProvider($item, $approved);

        return $item;
    }

    private function notifyProvider(BookingExtraItem $item, bool $approved): void
    {
        $booking = $item->booking()->with('provider.user')->first();
        $user = $booking?->provider?->user;
        if (! $user) {
            return;
        }

        try {
            $channels = ChannelResolver::resolve(array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]));
            $user->notify(new ProviderJobStatusNotification($approved ? 'extra_work_approved' : 'extra_work_declined', $booking, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver provider extra-work response for booking [{$booking->id}]: ".$e->getMessage());
        }
    }
}
