<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use App\Support\Journey\JourneyBuilder;
use Illuminate\Support\Carbon;

/**
 * REF 1CF-CANCEL-POLICY-001 — how long a job has been waiting for spare parts, read ONLY from the
 * append-only status history so a resume-then-re-hold can never reset it.
 *
 * Counted: every interval a job spent on hold for `awaiting_spares` where the professional or 1CallFix
 * sources the part. NOT counted: holds where the customer supplies their own part (tag [src=customer]),
 * and every other hold reason (customer approval, payment decision, provider-side faults).
 */
class SparesDelayClock
{
    public const HOLD_PREFIX = 'Hold reason: awaiting_spares';
    public const DEFAULT_DAYS = 10;
    public const DEFAULT_RESUME_GRACE_HOURS = 48;

    /** Settings scope hints for a booking (same cascade every cancellation setting uses). */
    public function scopeFor(Booking $booking): array
    {
        return PolicySettings::scopeFor($booking);
    }

    /**
     * Threshold in days, from the booking's policy snapshot. A per-category override
     * (`cancellation.spares_delay_days.category_{id}`) takes precedence when set.
     */
    public function thresholdDays(Booking $booking): int
    {
        $booking->loadMissing('service');

        $categoryId = $booking->service?->category_id;
        if ($categoryId) {
            $override = PolicySettings::get($booking, PolicySettings::CATEGORY_OVERRIDE_PREFIX.$categoryId);
            if ($override !== null && (int) $override > 0) {
                return (int) $override;
            }
        }

        return max(1, (int) PolicySettings::get($booking, 'cancellation.spares_delay_days'));
    }

    public function resumeGraceHours(Booking $booking): int
    {
        return max(1, (int) PolicySettings::get($booking, 'cancellation.spares_resume_grace_hours'));
    }

    /** Whole seconds of counted spares delay across every hold of this job. */
    public function countedSeconds(Booking $booking, ?Carbon $now = null): int
    {
        $now ??= now();
        $total = 0;
        $openFrom = null;
        $openCounts = false;

        $rows = $booking->statusHistory()->orderBy('changed_at')->orderBy('id')->get();

        foreach ($rows as $row) {
            $note = (string) $row->note;
            $startsSparesHold = $row->status === 'on_hold' && str_starts_with($note, self::HOLD_PREFIX);
            $startsOtherHold = $row->status === 'on_hold' && str_starts_with($note, 'Hold reason:') && ! $startsSparesHold;
            $leavesHold = $row->status !== 'on_hold';

            if ($startsSparesHold && $openFrom === null) {
                $openFrom = $row->changed_at;
                $openCounts = ! str_contains($note, '[src=customer]');

                continue;
            }

            if (($startsOtherHold || $leavesHold) && $openFrom !== null) {
                if ($openCounts) {
                    $total += max(0, $openFrom->diffInSeconds($row->changed_at, false));
                }
                $openFrom = null;
            }
        }

        if ($openFrom !== null && $booking->status === 'on_hold' && $booking->hold_reason === 'awaiting_spares' && $openCounts) {
            $total += max(0, $openFrom->diffInSeconds($now, false));
        }

        return (int) $total;
    }

    public function countedDays(Booking $booking, ?Carbon $now = null): int
    {
        return intdiv($this->countedSeconds($booking, $now), 86400);
    }

    /** The customer chose to supply their own part for the current hold — those days never count. */
    public function customerSupplied(Booking $booking): bool
    {
        return $booking->spares_sourced_by === 'customer';
    }

    /** The declared arrival date is itself already further away than the threshold: cancel is open right now. */
    public function earlyUnlocked(Booking $booking, ?Carbon $now = null): bool
    {
        if (! $booking->spares_expected_at || $this->customerSupplied($booking)) {
            return false;
        }

        $now ??= now();

        return $booking->spares_expected_at->copy()->startOfDay()
            ->gte($now->copy()->startOfDay()->addDays($this->thresholdDays($booking)));
    }

    public function unlocked(Booking $booking, ?Carbon $now = null): bool
    {
        if ($this->customerSupplied($booking)) {
            return false;
        }

        return $this->earlyUnlocked($booking, $now)
            || $this->countedSeconds($booking, $now) >= $this->thresholdDays($booking) * 86400;
    }

    /** When cancellation unlocks on the clock alone (null when it never will, e.g. customer-supplied part). */
    public function unlocksAt(Booking $booking, ?Carbon $now = null): ?Carbon
    {
        if ($this->customerSupplied($booking)) {
            return null;
        }

        $now ??= now();
        $remaining = $this->thresholdDays($booking) * 86400 - $this->countedSeconds($booking, $now);

        return $remaining <= 0 ? $now->copy() : $now->copy()->addSeconds($remaining);
    }

    /** When the professional marked "spares available" during the current hold (null if not yet). */
    public function sparesAvailableAt(Booking $booking): ?Carbon
    {
        $row = $booking->statusHistory()
            ->where('status', 'on_hold')
            ->where('note', 'like', JourneyBuilder::SPARES_NOTE.'%')
            ->when($booking->on_hold_since, fn ($q) => $q->where('changed_at', '>=', $booking->on_hold_since))
            ->orderBy('changed_at')
            ->first();

        return $row?->changed_at;
    }

    /** Spares were ready but the professional has not resumed within the grace window: the delay is theirs. */
    public function providerResumeOverdue(Booking $booking, ?Carbon $now = null): bool
    {
        if ($booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_spares') {
            return false;
        }

        $at = $this->sparesAvailableAt($booking);

        return $at !== null && $at->copy()->addHours($this->resumeGraceHours($booking))->lte($now ?? now());
    }
}
