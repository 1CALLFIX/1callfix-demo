<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A2 — post-payment overpricing disputes, and the refund they may lead to.
 *
 * Raising and resolving a dispute moves no money. A refund decision enters the MANUAL MONEY ACTIONS approval model
 * (CLAUDE.md), the same shape as MismatchRefundService:
 *  - permission  bookings.refund_dispute (Super Admin always holds it), checked here, server-side;
 *  - scope       franchise-scoped holders act only on their franchise's disputes; global/country/city holders are HQ;
 *                a booking with no franchise is HQ-only (fails closed);
 *  - limits      refund.dispute.franchise_limit / hq_limit (rupees, null = that level cannot approve); above = Super Admin;
 *  - maker-checker  refund.dispute.dual_approval_above (null = off): above it the requester can never approve;
 *  - escalation  refund.dispute.escalate_after_hours (null = off): franchise -> HQ -> Super Admin, once per level;
 *  - reason required, row-locked, idempotent (unique wallet ref), never automatic, full audit log;
 *  - money moves through the ledger only: the approved amount is credited to the customer's wallet (HQ ledger), so
 *    a refund can never become withdrawable cash and franchise users approve without ever holding funds.
 */
class BookingDisputeService
{
    public const PERMISSION = 'bookings.refund_dispute';

    public const LEVEL_FRANCHISE = 1;

    public const LEVEL_HQ = 2;

    public const LEVEL_SUPER = 3;

    public const FRANCHISE_LIMIT_KEY = 'refund.dispute.franchise_limit';

    public const HQ_LIMIT_KEY = 'refund.dispute.hq_limit';

    public const DUAL_APPROVAL_KEY = 'refund.dispute.dual_approval_above';

    public const ESCALATE_HOURS_KEY = 'refund.dispute.escalate_after_hours';

    public function __construct(private AuthorizationService $authz, private WalletService $wallet)
    {
    }

    // ============================== customer side ==============================

    /** Whether the customer can raise a dispute on this booking right now (null) or why not (message). */
    public function cannotRaise(Booking $booking, int $customerId): ?string
    {
        if ($booking->customer_id !== $customerId) {
            return 'This booking is not yours.';
        }
        if (! in_array($booking->status, ['completed', 'cancelled'], true) || $this->amountPaid($booking) <= 0) {
            return 'You can raise a dispute once the booking is closed and has been paid.';
        }
        if (BookingDispute::where('booking_id', $booking->id)->where('status', BookingDispute::OPEN)->exists()) {
            return 'You already have a dispute open for this booking. Our team is reviewing it.';
        }

        return null;
    }

    public function amountPaid(Booking $booking): float
    {
        return round((float) Payment::where('booking_id', $booking->id)->where('status', 'captured')->sum('amount'), 2);
    }

    /** @throws \RuntimeException */
    public function raise(int $bookingId, int $customerId, string $reason): BookingDispute
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \RuntimeException('Tell us what looks wrong (a reason is required).');
        }

        return DB::transaction(function () use ($bookingId, $customerId, $reason) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($message = $this->cannotRaise($booking, $customerId)) {
                throw new \RuntimeException($message);
            }

            $dispute = BookingDispute::create([
                'booking_id' => $booking->id,
                'customer_id' => $customerId,
                'franchise_id' => $booking->franchise_id,
                'reason' => mb_substr($reason, 0, 1000),
                'amount_paid' => $this->amountPaid($booking),
                'status' => BookingDispute::OPEN,
            ]);

            ActivityLogger::logModel(User::find($customerId), $dispute, "Customer raised a pricing dispute (booking {$booking->code})", ['reason' => $reason]);

            return $dispute;
        });
    }

    // ============================== settings / levels ==============================

    private function settingRupees(string $key): ?float
    {
        $value = Setting::get($key);

        return ($value === null || trim((string) $value) === '') ? null : round((float) $value, 2);
    }

    public function escalateAfterHours(): ?int
    {
        $value = Setting::get(self::ESCALATE_HOURS_KEY);

        return ($value === null || trim((string) $value) === '' || (int) $value < 1) ? null : (int) $value;
    }

    /** The most this level may refund (rupees). Null = not configured = cannot approve. */
    public function limit(int $level): ?float
    {
        return match ($level) {
            self::LEVEL_SUPER => (float) PHP_INT_MAX,
            self::LEVEL_HQ => $this->settingRupees(self::HQ_LIMIT_KEY),
            self::LEVEL_FRANCHISE => $this->settingRupees(self::FRANCHISE_LIMIT_KEY),
            default => null,
        };
    }

    private function needsDualApproval(BookingDispute $d): bool
    {
        $threshold = $this->settingRupees(self::DUAL_APPROVAL_KEY);

        return $threshold !== null && (float) $d->refund_amount > $threshold;
    }

    private function scopeFor(?int $franchiseId): array
    {
        if (! $franchiseId) {
            return [];
        }
        $franchise = Franchise::find($franchiseId);

        return array_filter(['franchise_id' => $franchiseId, 'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id]);
    }

    /** 3 = Super Admin, 2 = HQ grant, 1 = franchise grant, null = no permission over this franchise. */
    public function levelOf(User $user, ?int $franchiseId): ?int
    {
        if ($user->role === 'super_admin') {
            return self::LEVEL_SUPER;
        }

        $scope = $this->scopeFor($franchiseId);
        $best = null;

        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', self::PERMISSION)) {
                continue;
            }
            if (! $this->authz->scopeCovers($assignment->scope_type, $assignment->scope_id, $scope)) {
                continue;
            }
            $best = max($best ?? 0, $assignment->scope_type === 'franchise' ? self::LEVEL_FRANCHISE : self::LEVEL_HQ);
        }

        return $best;
    }

    public function canEnter(User $user): bool
    {
        return $this->authz->canAnywhere($user, self::PERMISSION);
    }

    /** Disputes this user may see: Super Admin / global = all; franchise / city / country grants = their franchises. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'super_admin') {
            return $query;
        }

        $ids = [];
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', self::PERMISSION)) {
                continue;
            }
            switch ($assignment->scope_type) {
                case 'global':
                    return $query;
                case 'franchise':
                    $ids[] = (int) $assignment->scope_id;
                    break;
                case 'city':
                    $ids = array_merge($ids, Franchise::where('city_id', $assignment->scope_id)->pluck('id')->all());
                    break;
                case 'country':
                    $ids = array_merge($ids, Franchise::where('country_id', $assignment->scope_id)->pluck('id')->all());
                    break;
            }
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('franchise_id', array_unique($ids));
    }

    private function canCover(?int $level, BookingDispute $d): bool
    {
        if ($level === null) {
            return false;
        }
        $limit = $this->limit($level);

        return $limit !== null && (float) $d->refund_amount <= $limit;
    }

    /** What this user can do to this dispute now: 'resolve' | 'request' | 'approve' | 'retry' | null. UI only; every action re-checks. */
    public function availableAction(BookingDispute $d, User $user): ?string
    {
        $level = $this->levelOf($user, $d->franchise_id);
        if ($level === null) {
            return null;
        }

        if ($d->status === BookingDispute::OPEN) {
            return 'resolve';
        }

        return match ($d->refund_status) {
            BookingDispute::REFUND_AWAITING_REQUEST => 'request',
            BookingDispute::REFUND_AWAITING_APPROVAL => ($user->id !== $d->refund_requested_by_id && $this->canCover($level, $d)) ? 'approve' : null,
            BookingDispute::REFUND_FAILED => $this->canCover($level, $d) ? 'retry' : null,
            default => null,
        };
    }

    public function canReject(BookingDispute $d, User $user): bool
    {
        return $d->refund_status === BookingDispute::REFUND_AWAITING_APPROVAL
            && $user->id !== $d->refund_requested_by_id
            && $this->canCover($this->levelOf($user, $d->franchise_id), $d);
    }

    // ============================== admin actions ==============================

    /**
     * Close the dispute with the outcome and a note. A 'refund' outcome records the amount decided and puts the
     * refund in the approval queue — it does not move money.
     *
     * @return array{ok: bool, message: string}
     */
    public function resolve(BookingDispute $dispute, User $admin, string $outcome, string $note, ?float $refundAmount = null): array
    {
        $note = trim($note);

        return DB::transaction(function () use ($dispute, $admin, $outcome, $note, $refundAmount) {
            $d = BookingDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($d->status !== BookingDispute::OPEN) {
                return $this->no('This dispute is already resolved.');
            }
            if ($this->levelOf($admin, $d->franchise_id) === null) {
                return $this->no('You are not permitted to resolve this dispute.');
            }
            if (! array_key_exists($outcome, BookingDispute::OUTCOMES)) {
                return $this->no('Choose an outcome.');
            }
            if ($note === '') {
                return $this->no('Record how the dispute was resolved (a note is required).');
            }
            if ($outcome === 'refund') {
                $refundAmount = round((float) $refundAmount, 2);
                if ($refundAmount <= 0 || $refundAmount > (float) $d->amount_paid) {
                    return $this->no('The refund must be more than zero and not more than the '.number_format((float) $d->amount_paid, 2).' the customer paid.');
                }
                $d->refund_amount = $refundAmount;
                $d->refund_status = BookingDispute::REFUND_AWAITING_REQUEST;
            }

            $d->status = BookingDispute::RESOLVED;
            $d->outcome = $outcome;
            $d->resolution_note = $note;
            $d->resolved_by_id = $admin->id;
            $d->resolved_at = now();
            $d->save();

            ActivityLogger::logModel($admin, $d, "Booking dispute #{$d->id} resolved: {$outcome}", ['note' => $note, 'refund_amount' => $d->refund_amount]);

            return ['ok' => true, 'message' => $outcome === 'refund'
                ? 'Dispute resolved. The refund now needs a request and approval before any money moves.'
                : 'Dispute resolved and recorded.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function requestRefund(BookingDispute $dispute, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($dispute, $actor, $reason) {
            $d = BookingDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($d->refund_status !== BookingDispute::REFUND_AWAITING_REQUEST) {
                return $this->no('This refund is not waiting for a request.');
            }
            $level = $this->levelOf($actor, $d->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to refund this dispute.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $d->refund_requested_by_id = $actor->id;
            $d->refund_request_reason = $reason;
            $d->refund_requested_at = now();

            ActivityLogger::logModel($actor, $d, "Dispute refund requested (#{$d->id})", ['amount' => $d->refund_amount, 'reason' => $reason, 'level' => $level]);

            if (! $this->needsDualApproval($d) && $this->canCover($level, $d)) {
                return $this->execute($d, $actor, $reason);
            }

            $d->refund_status = BookingDispute::REFUND_AWAITING_APPROVAL;
            $d->save();

            return ['ok' => true, 'message' => 'Request recorded. It now needs approval from a different user with a sufficient limit.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function approveRefund(BookingDispute $dispute, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($dispute, $actor, $reason) {
            $d = BookingDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($d->refund_status !== BookingDispute::REFUND_AWAITING_APPROVAL) {
                return $this->no('This refund is not awaiting approval.');
            }
            $level = $this->levelOf($actor, $d->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to approve this refund.');
            }
            if ($actor->id === $d->refund_requested_by_id) {
                return $this->no('You cannot approve your own request. A different user must approve it.');
            }
            if (! $this->canCover($level, $d)) {
                return $this->no('Your approval limit does not cover this amount.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $d->refund_approved_by_id = $actor->id;
            $d->refund_approval_reason = $reason;
            $d->refund_approved_at = now();

            ActivityLogger::logModel($actor, $d, "Dispute refund approved (#{$d->id})", ['amount' => $d->refund_amount, 'reason' => $reason, 'requested_by_id' => $d->refund_requested_by_id]);

            return $this->execute($d, $actor, $reason);
        });
    }

    /** An eligible approver sends the request back; the clock restarts. @return array{ok: bool, message: string} */
    public function rejectRefund(BookingDispute $dispute, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($dispute, $actor, $reason) {
            $d = BookingDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($d->refund_status !== BookingDispute::REFUND_AWAITING_APPROVAL) {
                return $this->no('This refund is not awaiting approval.');
            }
            $level = $this->levelOf($actor, $d->franchise_id);
            if ($level === null || $actor->id === $d->refund_requested_by_id || ! $this->canCover($level, $d)) {
                return $this->no('You cannot reject this request (permission, own request, or above your limit).');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            ActivityLogger::logModel($actor, $d, "Dispute refund rejected (#{$d->id})", [
                'rejection_reason' => $reason, 'requested_by_id' => $d->refund_requested_by_id, 'request_reason' => $d->refund_request_reason,
            ]);

            $d->refund_status = BookingDispute::REFUND_AWAITING_REQUEST;
            $d->refund_requested_by_id = null;
            $d->refund_request_reason = null;
            $d->refund_requested_at = null;
            $d->refund_rejected_at = now();
            $d->escalation_level = 0;
            $d->last_escalated_at = null;
            $d->save();

            return ['ok' => true, 'message' => 'Request rejected. The refund is back awaiting a new request.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function retry(BookingDispute $dispute, User $actor): array
    {
        return DB::transaction(function () use ($dispute, $actor) {
            $d = BookingDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($d->refund_status !== BookingDispute::REFUND_FAILED) {
                return $this->no('Only a failed refund can be retried.');
            }
            if (! $this->canCover($this->levelOf($actor, $d->franchise_id), $d)) {
                return $this->no('You are not permitted to retry this refund, or it is above your limit.');
            }

            ActivityLogger::logModel($actor, $d, "Dispute refund retry (#{$d->id})");

            return $this->execute($d, $actor, $d->refund_approval_reason ?: (string) $d->refund_request_reason);
        });
    }

    /** Runs inside the caller's transaction with $d locked. A ledger failure is recorded, not thrown. */
    private function execute(BookingDispute $d, User $actor, string $reason): array
    {
        $booking = Booking::with('customer')->find($d->booking_id);
        $amount = round((float) $d->refund_amount, 2);

        try {
            $this->wallet->credit(
                $booking->customer,
                $amount,
                reason: "Refund after pricing review — booking {$booking->code}",
                ref: "booking:{$booking->id}:wallet-refund:dispute-{$d->id}", // unique at the ledger: one refund per dispute, ever; labelled "Refund" by WalletSourceLabel
                actorId: $actor->id,
            );
        } catch (\Throwable $e) {
            $d->refund_status = BookingDispute::REFUND_FAILED;
            $d->refund_failure_message = mb_substr($e->getMessage(), 0, 1000);
            $d->save();

            ActivityLogger::logModel($actor, $d, "Dispute refund FAILED (#{$d->id})", ['amount' => $amount, 'error' => $e->getMessage()]);

            return $this->no('The refund could not be credited. Nothing was refunded; you can retry. Details are in the activity log.');
        }

        $d->refund_status = BookingDispute::REFUNDED;
        $d->refunded_at = now();
        $d->refund_failure_message = null;
        $d->save();

        ActivityLogger::logModel($actor, $d, "Dispute refunded to wallet (#{$d->id})", [
            'amount' => $amount, 'reason' => $reason, 'requested_by_id' => $d->refund_requested_by_id, 'approved_by_id' => $d->refund_approved_by_id,
        ]);

        return ['ok' => true, 'message' => '₹'.number_format($amount, 2).' credited to the customer\'s wallet.'];
    }

    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    // ============================== escalation ==============================

    /** The lowest level whose configured limit covers this refund. */
    public function handlerLevel(BookingDispute $d): int
    {
        $franchiseLimit = $this->limit(self::LEVEL_FRANCHISE);
        $hqLimit = $this->limit(self::LEVEL_HQ);
        $amount = (float) $d->refund_amount;

        if ($d->franchise_id && $franchiseLimit !== null && $amount <= $franchiseLimit) {
            return self::LEVEL_FRANCHISE;
        }
        if ($hqLimit !== null && $amount <= $hqLimit) {
            return self::LEVEL_HQ;
        }

        return self::LEVEL_SUPER;
    }

    /**
     * Raise the escalation level of every open refund older than the configured hours, one level per elapsed
     * interval (franchise -> HQ -> Super Admin), once per level. The level shows in the queue and the audit log.
     *
     * @return int number of escalations recorded
     */
    public function escalateOverdue(): int
    {
        $hours = $this->escalateAfterHours();
        if ($hours === null) {
            return 0;
        }

        $raised = 0;

        BookingDispute::query()
            ->whereIn('refund_status', BookingDispute::REFUND_OPEN)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($hours, &$raised) {
                foreach ($rows as $d) {
                    $handler = $this->handlerLevel($d);
                    $steps = intdiv((int) $d->refundClockStartedAt()->diffInHours(now()), $hours);
                    $target = min(self::LEVEL_SUPER, $handler + $steps);

                    if ($target <= $handler || $target <= $d->escalation_level) {
                        continue;
                    }

                    $d->update(['escalation_level' => $target, 'last_escalated_at' => now()]);
                    ActivityLogger::logModel(null, $d, "Dispute refund #{$d->id} escalated to level {$target}", ['hours' => $hours]);
                    $raised++;
                }
            });

        return $raised;
    }
}
