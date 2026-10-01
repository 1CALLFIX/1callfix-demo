<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AdminOpsAlertService;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-JOURNEY-001 — "the professional left the site / cannot continue" in the middle of the work.
 *
 * Reported by the customer ("my professional left"), by the professional ("I can't continue") or marked
 * by an operator. It puts the job on a PROVIDER-SIDE hold (the existing hold layer: `provider_unresponsive`
 * or `other_provider_issue`), tells the customer, raises a scoped operator alert and writes the audit
 * trail. Nothing is charged, nothing is refunded, nobody is paid at this point — the operator then either
 * hands the job to another professional (ReassignInProgressJobAction) or cancels it, in which case the
 * cancellation fee is waived automatically because the hold is provider-side.
 */
class FlagProviderLeftAction
{
    public const SOURCES = ['customer', 'provider', 'operator'];

    private const NOTES = [
        'customer' => 'Customer reports the professional left the site',
        'provider' => 'Professional says they cannot continue',
        'operator' => 'Operator marked: professional left the site',
    ];

    /**
     * @throws \InvalidArgumentException for an unknown source
     * @throws \RuntimeException if the job is not in progress
     */
    public function execute(int $bookingId, string $source, ?int $actorId = null, ?string $note = null): Booking
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown report source: {$source}");
        }

        $booking = Booking::findOrFail($bookingId);
        if ($booking->status !== 'in_progress') {
            throw new \RuntimeException('Only a job that is in progress can be reported as left.');
        }

        $text = self::NOTES[$source].(trim((string) $note) !== '' ? ' — '.trim($note) : '');
        $reason = $source === 'provider' ? 'other_provider_issue' : 'provider_unresponsive';

        $booking = (new PlaceBookingOnHoldAction())->execute($bookingId, $reason, $text);

        try {
            ActivityLogger::logModel($actorId ? User::find($actorId) : null, $booking, 'job flagged: professional left', [
                'source' => $source, 'reason' => $reason, 'provider_id' => $booking->provider_id,
            ]);
            app(AdminOpsAlertService::class)->jobAtRisk($booking);
        } catch (\Throwable $e) {
            Log::error("Failed to raise job-at-risk signals for booking [{$booking->id}]: ".$e->getMessage());
        }

        return $booking;
    }
}
