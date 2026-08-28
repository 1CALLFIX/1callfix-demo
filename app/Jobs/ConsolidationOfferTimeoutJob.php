<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase E4. The expiry + fallback half of bundle dispatch consolidation,
 * mirroring the "offer -> wait -> time out the stale attempt -> fall through
 * to standard dispatch" shape ServiceMatchingJob already uses for its own
 * offers. Queued (delayed) by BundleConsolidationJob once it has offered a
 * bundle sibling to the provider who took an earlier sibling.
 *
 *   - If the provider accepted in the meantime, the `dispatch_attempts` row
 *     is already `accepted` (AcceptBookingAction did it, with its own
 *     availability recheck) — nothing to do.
 *   - Otherwise the still-`notified` attempt is closed out as `timeout` and
 *     the sibling is handed to the existing ServiceMatchingJob, exactly as
 *     if the consolidation offer had never happened.
 *
 * This job never assigns a provider and never touches booking status — it
 * only ages out one offer and re-arms standard dispatch.
 */
class ConsolidationOfferTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DISPATCHABLE_STATUSES = ['pending', 'searching_provider'];

    public function __construct(
        public int $siblingBookingId,
        public int $dispatchAttemptId,
    ) {
    }

    public function handle(): void
    {
        $attempt = DispatchAttempt::find($this->dispatchAttemptId);

        if (! $attempt || $attempt->status !== 'notified') {
            // Already accepted (provider took it) or already timed out by a
            // standard ServiceMatchingJob round — nothing for this job to do.
            return;
        }

        $attempt->status = 'timeout';
        $attempt->responded_at = now();
        $attempt->save();

        $sibling = Booking::find($this->siblingBookingId);

        if ($sibling
            && $sibling->provider_id === null
            && in_array($sibling->status, self::DISPATCHABLE_STATUSES, true)) {
            ServiceMatchingJob::dispatch($sibling->id);
        }
    }
}
