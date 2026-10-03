<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Franchise;
use App\Models\MismatchRefund;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AdminOpsAlertService;
use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The approval model (CLAUDE.md "MANUAL MONEY ACTIONS") for refunding a
 * Razorpay capture whose amount did not match the Payment row.
 *
 *  - permission  payments.refund_mismatch (Super Admin always holds it);
 *  - scope       franchise-scoped holders act only on their franchise's
 *                rows; global/country/city holders are "HQ"; a payment with
 *                no resolvable franchise is HQ-only (fails closed);
 *  - limits      refund.mismatch.franchise_limit / hq_limit (rupees, null =
 *                that level cannot approve); above hq_limit = Super Admin;
 *  - maker-checker  refund.mismatch.dual_approval_above (null = off): above
 *                it the requester can never approve their own request;
 *  - escalation  refund.mismatch.escalate_after_hours (null = off): once
 *                per level, franchise -> HQ -> Super Admin;
 *  - always the exact captured amount (paise, from Razorpay's own payload),
 *    reason required, row-locked, idempotent, never automatic, the Payment
 *    row is never marked paid, and the money goes back through the HQ
 *    gateway — franchise users approve, they never move funds.
 */
class MismatchRefundService
{
    public const PERMISSION = 'payments.refund_mismatch';

    public const REFUNDED_OUTCOME = 'amount_mismatch_refunded';

    public const LEVEL_FRANCHISE = 1;

    public const LEVEL_HQ = 2;

    public const LEVEL_SUPER = 3;

    public const FRANCHISE_LIMIT_KEY = 'refund.mismatch.franchise_limit';

    public const HQ_LIMIT_KEY = 'refund.mismatch.hq_limit';

    public const DUAL_APPROVAL_KEY = 'refund.mismatch.dual_approval_above';

    public const ESCALATE_HOURS_KEY = 'refund.mismatch.escalate_after_hours';

    public function __construct(
        private PaymentGateway $gateway,
        private AuthorizationService $authz,
        private AmountMismatchService $notices,
    ) {
    }

    // ============================== settings ==============================

    /** Rupees setting → paise; null / blank = not configured. */
    private function settingPaise(string $key): ?int
    {
        $value = Setting::get($key);

        return ($value === null || trim((string) $value) === '') ? null : (int) round(((float) $value) * 100);
    }

    public function escalateAfterHours(): ?int
    {
        $value = Setting::get(self::ESCALATE_HOURS_KEY);

        return ($value === null || trim((string) $value) === '' || (int) $value < 1) ? null : (int) $value;
    }

    /** The most this level may refund, in paise. Null = not configured = cannot approve. */
    public function limitPaise(int $level): ?int
    {
        return match ($level) {
            self::LEVEL_SUPER => PHP_INT_MAX,
            self::LEVEL_HQ => $this->settingPaise(self::HQ_LIMIT_KEY),
            self::LEVEL_FRANCHISE => $this->settingPaise(self::FRANCHISE_LIMIT_KEY),
            default => null,
        };
    }

    private function needsDualApproval(MismatchRefund $row): bool
    {
        $threshold = $this->settingPaise(self::DUAL_APPROVAL_KEY);

        return $threshold !== null && $row->amount_paise > $threshold;
    }

    // ============================== levels & scope ==============================

    private function scopeFor(?int $franchiseId): array
    {
        if (! $franchiseId) {
            return [];
        }

        $franchise = Franchise::find($franchiseId);

        return array_filter([
            'franchise_id' => $franchiseId,
            'city_id' => $franchise?->city_id,
            'country_id' => $franchise?->country_id,
        ]);
    }

    /** 3 = Super Admin, 2 = HQ (global/country/city grant), 1 = franchise grant, null = no permission over this franchise. */
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

            $level = $assignment->scope_type === 'franchise' ? self::LEVEL_FRANCHISE : self::LEVEL_HQ;
            $best = max($best ?? 0, $level);
        }

        return $best;
    }

    /** True if this user holds the permission at all (for screen entry); rows are still filtered by visibleTo(). */
    public function canEnter(User $user): bool
    {
        return $this->authz->canAnywhere($user, self::PERMISSION);
    }

    /** Rows this user may see: Super Admin / global = all; franchise / city / country grants = their franchises. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'super_admin') {
            return $query;
        }

        $franchiseIds = [];

        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', self::PERMISSION)) {
                continue;
            }

            switch ($assignment->scope_type) {
                case 'global':
                    return $query;
                case 'franchise':
                    $franchiseIds[] = (int) $assignment->scope_id;
                    break;
                case 'city':
                    $franchiseIds = array_merge($franchiseIds, Franchise::where('city_id', $assignment->scope_id)->pluck('id')->all());
                    break;
                case 'country':
                    $franchiseIds = array_merge($franchiseIds, Franchise::where('country_id', $assignment->scope_id)->pluck('id')->all());
                    break;
            }
        }

        return $franchiseIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('franchise_id', array_unique($franchiseIds));
    }

    public function franchiseIdFor(?Payment $payment): ?int
    {
        if (! $payment) {
            return null;
        }

        foreach (['booking', 'bookingBundle', 'parcelOrder', 'taxiRide', 'propertyReservation', 'marketplaceOrder', 'rentalReservation', 'hotelReservation'] as $relation) {
            $order = $payment->{$relation};
            if ($order && method_exists($order, 'orderFranchiseId') && $order->orderFranchiseId()) {
                return (int) $order->orderFranchiseId();
            }
        }

        return $payment->user?->franchise_id ? (int) $payment->user->franchise_id : null;
    }

    private function canCover(?int $level, MismatchRefund $row): bool
    {
        if ($level === null) {
            return false;
        }

        $limit = $this->limitPaise($level);

        return $limit !== null && $row->amount_paise <= $limit;
    }

    /** What this user can do to this row right now: 'request' | 'approve' | 'retry' | null. For the queue UI only; every action re-checks. */
    public function availableAction(MismatchRefund $row, User $user): ?string
    {
        $level = $this->levelOf($user, $row->franchise_id);

        if ($level === null || $row->amount_paise <= 0) {
            return null;
        }

        return match ($row->status) {
            MismatchRefund::AWAITING_REQUEST => 'request',
            MismatchRefund::AWAITING_APPROVAL => ($user->id !== $row->requested_by_id && $this->canCover($level, $row)) ? 'approve' : null,
            MismatchRefund::FAILED => $this->canCover($level, $row) ? 'retry' : null,
            default => null,
        };
    }

    // ============================== queue rows ==============================

    /** Idempotent: one row per gateway payment, created when a mismatch is logged. */
    public function ensureForLog(PaymentWebhookLog $log): ?MismatchRefund
    {
        if ($log->outcome !== RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH) {
            return null;
        }

        $entity = $log->payload['payload']['payment']['entity'] ?? [];
        $gatewayPaymentId = $log->gateway_payment_id ?: ($entity['id'] ?? null);

        if (! $gatewayPaymentId) {
            return null;
        }

        $captured = $entity['amount'] ?? null;
        $amountPaise = (is_int($captured) || (is_string($captured) && ctype_digit($captured))) ? (int) $captured : 0;
        $payment = $log->payment_id ? Payment::find($log->payment_id) : null;

        try {
            return MismatchRefund::firstOrCreate(['gateway_payment_id' => $gatewayPaymentId], [
                'payment_webhook_log_id' => $log->id,
                'payment_id' => $payment?->id,
                'franchise_id' => $this->franchiseIdFor($payment),
                'amount_paise' => $amountPaise,
                'status' => MismatchRefund::AWAITING_REQUEST,
            ]);
        } catch (QueryException $e) {
            // Lost a creation race on the unique key — the winner's row is the one.
            return MismatchRefund::where('gateway_payment_id', $gatewayPaymentId)->first();
        }
    }

    /** Backfill for mismatches logged before this queue existed. Idempotent and chunked. */
    public function syncFromLogs(): void
    {
        PaymentWebhookLog::query()
            ->where('outcome', RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH)
            ->whereNotNull('gateway_payment_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('mismatch_refunds')
                ->whereColumn('mismatch_refunds.gateway_payment_id', 'payment_webhook_logs.gateway_payment_id'))
            ->orderBy('id')
            ->chunkById(200, fn ($logs) => $logs->each(fn ($log) => $this->ensureForLog($log)));
    }

    // ============================== actions ==============================

    /** @return array{ok: bool, message: string} */
    public function request(MismatchRefund $row, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($row, $actor, $reason) {
            $r = MismatchRefund::whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($r->status !== MismatchRefund::AWAITING_REQUEST) {
                return $this->no(match ($r->status) {
                    MismatchRefund::AWAITING_APPROVAL => 'A refund has already been requested and is awaiting approval.',
                    MismatchRefund::REFUNDED => 'This payment has already been refunded.',
                    default => 'This refund failed at the gateway. Use Retry.',
                });
            }

            $level = $this->levelOf($actor, $r->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to refund this payment.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }
            if ($r->amount_paise <= 0) {
                return $this->no('The captured amount is missing from the gateway payload, so it cannot be refunded here. Handle it in the Razorpay dashboard.');
            }

            $r->requested_by_id = $actor->id;
            $r->request_reason = $reason;
            $r->requested_at = now();

            ActivityLogger::logModel($actor, $r, "Mismatch refund requested (queue #{$r->id})", [
                'gateway_payment_id' => $r->gateway_payment_id, 'amount_paise' => $r->amount_paise, 'reason' => $reason, 'level' => $level,
            ]);

            if (! $this->needsDualApproval($r) && $this->canCover($level, $r)) {
                return $this->execute($r, $actor, $reason);
            }

            $r->status = MismatchRefund::AWAITING_APPROVAL;
            $r->save();

            return ['ok' => true, 'message' => 'Request recorded. It now needs approval from a different user with a sufficient limit.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function approve(MismatchRefund $row, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($row, $actor, $reason) {
            $r = MismatchRefund::whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($r->status !== MismatchRefund::AWAITING_APPROVAL) {
                return $this->no('This refund is not awaiting approval.');
            }

            $level = $this->levelOf($actor, $r->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to approve this refund.');
            }
            if ($actor->id === $r->requested_by_id) {
                return $this->no('You cannot approve your own request. A different user must approve it.');
            }
            if (! $this->canCover($level, $r)) {
                return $this->no('Your approval limit does not cover this amount.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $r->approved_by_id = $actor->id;
            $r->approval_reason = $reason;
            $r->approved_at = now();

            ActivityLogger::logModel($actor, $r, "Mismatch refund approved (queue #{$r->id})", [
                'gateway_payment_id' => $r->gateway_payment_id, 'amount_paise' => $r->amount_paise, 'reason' => $reason, 'requested_by_id' => $r->requested_by_id,
            ]);

            return $this->execute($r, $actor, $reason);
        });
    }

    /** Retry after a gateway failure: both steps already happened, so only the permission, scope and limit are re-checked. @return array{ok: bool, message: string} */
    public function retry(MismatchRefund $row, User $actor): array
    {
        return DB::transaction(function () use ($row, $actor) {
            $r = MismatchRefund::whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($r->status !== MismatchRefund::FAILED) {
                return $this->no('Only a failed refund can be retried.');
            }
            if (! $this->canCover($this->levelOf($actor, $r->franchise_id), $r)) {
                return $this->no('You are not permitted to retry this refund, or it is above your limit.');
            }

            ActivityLogger::logModel($actor, $r, "Mismatch refund retry (queue #{$r->id})", ['gateway_payment_id' => $r->gateway_payment_id]);

            return $this->execute($r, $actor, $r->approval_reason ?: (string) $r->request_reason);
        });
    }

    /** Runs inside the caller's transaction with $r locked. A gateway failure is recorded, not thrown, so that state commits. */
    private function execute(MismatchRefund $r, User $actor, string $reason): array
    {
        $amount = $r->amountRupees();

        try {
            $refund = $this->gateway->refund($r->gateway_payment_id, $amount, 'Amount mismatch refund: '.$reason);
        } catch (\Throwable $e) {
            $r->status = MismatchRefund::FAILED;
            $r->failure_message = mb_substr($e->getMessage(), 0, 1000);
            $r->save();

            ActivityLogger::logModel($actor, $r, "Mismatch refund FAILED (queue #{$r->id})", [
                'gateway_payment_id' => $r->gateway_payment_id, 'amount_paise' => $r->amount_paise, 'reason' => $reason, 'error' => $e->getMessage(),
            ]);

            return $this->no('The gateway refused the refund. Nothing was refunded; you can retry. Details are in the activity log.');
        }

        $r->status = MismatchRefund::REFUNDED;
        $r->refunded_at = now();
        $r->gateway_refund_id = is_array($refund) ? ($refund['id'] ?? null) : null;
        $r->failure_message = null;
        $noticeDue = $r->refund_notice_sent_at === null;
        if ($noticeDue) {
            $r->refund_notice_sent_at = now(); // claimed under the row lock: the notice goes out once
        }
        $r->save();

        PaymentWebhookLog::where('gateway_payment_id', $r->gateway_payment_id)
            ->where('outcome', RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH)
            ->update(['outcome' => self::REFUNDED_OUTCOME, 'processed' => true]);

        ActivityLogger::logModel($actor, $r, "Refunded mismatched payment (queue #{$r->id})", [
            'gateway_payment_id' => $r->gateway_payment_id,
            'amount_paise' => $r->amount_paise,
            'reason' => $reason,
            'gateway_refund_id' => $r->gateway_refund_id,
            'requested_by_id' => $r->requested_by_id,
            'approved_by_id' => $r->approved_by_id,
        ]);

        if ($noticeDue) {
            $this->notices->notifyRefunded($r->payment, $amount);
        }

        return ['ok' => true, 'message' => '₹'.number_format($amount, 2).' refunded to the customer\'s original payment method.'];
    }

    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    // ============================== escalation ==============================

    /** The lowest level whose configured limit covers this row. */
    public function handlerLevel(MismatchRefund $row): int
    {
        $franchiseLimit = $this->limitPaise(self::LEVEL_FRANCHISE);
        $hqLimit = $this->limitPaise(self::LEVEL_HQ);

        if ($row->franchise_id && $franchiseLimit !== null && $row->amount_paise <= $franchiseLimit) {
            return self::LEVEL_FRANCHISE;
        }

        if ($hqLimit !== null && $row->amount_paise <= $hqLimit) {
            return self::LEVEL_HQ;
        }

        return self::LEVEL_SUPER;
    }

    /**
     * Alert the next level up for every open row older than the configured
     * hours — once per level (franchise -> HQ at N hours, HQ -> Super Admin
     * at 2N), so 100 branches produce a bounded number of alerts. A row that
     * has reached Super Admin stays visible in the queue with its age.
     *
     * @return int number of escalation alerts raised
     */
    public function escalateOverdue(): int
    {
        $hours = $this->escalateAfterHours();

        if ($hours === null) {
            return 0;
        }

        $this->syncFromLogs();

        $raised = 0;

        MismatchRefund::query()
            ->whereIn('status', MismatchRefund::OPEN)
            ->where('created_at', '<=', now()->subHours($hours))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($hours, &$raised) {
                foreach ($rows as $row) {
                    $handler = $this->handlerLevel($row);
                    $steps = intdiv((int) $row->created_at->diffInHours(now()), $hours);
                    $target = min(self::LEVEL_SUPER, $handler + $steps);

                    if ($target <= $handler || $target <= $row->escalation_level) {
                        continue;
                    }

                    app(AdminOpsAlertService::class)->mismatchRefundEscalation($row, $target);
                    $row->update(['escalation_level' => $target, 'last_escalated_at' => now()]);
                    $raised++;
                }
            });

        return $raised;
    }
}
