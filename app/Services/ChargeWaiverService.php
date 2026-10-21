<?php

namespace App\Services;

use App\Actions\ResolveInterimDisputeAction;
use App\Actions\WaiveCancellationChargeAction;
use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\ChargeWaiver;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * F1/F2 — waiving a customer charge is a MANUAL MONEY ACTION (CLAUDE.md), same shape as BookingDisputeService:
 *  - permission  bookings.waive_charge (Super Admin always holds it), checked here, server-side;
 *  - scope       franchise-scoped holders act only on their franchise's bookings; global/country/city holders are HQ;
 *                a booking with no franchise is HQ-only (fails closed);
 *  - limits      waiver.charge.franchise_limit / hq_limit (rupees, null = that level cannot approve); above = Super Admin;
 *  - maker-checker  waiver.charge.dual_approval_above (null = off): above it the requester can never approve;
 *  - escalation  waiver.charge.escalate_after_hours (null = off): franchise -> HQ -> Super Admin, once per level;
 *  - reason required, row-locked, idempotent, never automatic, full audit log;
 *  - money returned to the customer goes back the way it came (gateway refund or wallet ledger), never as cash.
 *
 * Three kinds of waiver:
 *  - cancellation_request  a pending customer cancellation charge (WaiveCancellationChargeAction on approval);
 *  - cancel_fee            the fee kept when an admin cancelled; on approval it is zeroed and whatever was kept from the
 *                          customer's payment is returned;
 *  - interim_reduction     a disputed work-done amount lowered by (old - new) (ResolveInterimDisputeAction on approval).
 *
 * Within the actor's own limit and below the second-approval threshold a waiver applies at once (recorded as applied
 * by the same user); otherwise it waits in the queue for a different user with a sufficient limit.
 */
class ChargeWaiverService
{
    public const PERMISSION = 'bookings.waive_charge';

    public const LEVEL_FRANCHISE = 1;

    public const LEVEL_HQ = 2;

    public const LEVEL_SUPER = 3;

    public const FRANCHISE_LIMIT_KEY = 'waiver.charge.franchise_limit';

    public const HQ_LIMIT_KEY = 'waiver.charge.hq_limit';

    public const DUAL_APPROVAL_KEY = 'waiver.charge.dual_approval_above';

    public const ESCALATE_HOURS_KEY = 'waiver.charge.escalate_after_hours';

    public function __construct(private AuthorizationService $authz, private WalletService $wallet, private PaymentGateway $gateway)
    {
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

    /** The most this level may waive (rupees). Null = not configured = cannot approve. */
    public function limit(int $level): ?float
    {
        return match ($level) {
            self::LEVEL_SUPER => (float) PHP_INT_MAX,
            self::LEVEL_HQ => $this->settingRupees(self::HQ_LIMIT_KEY),
            self::LEVEL_FRANCHISE => $this->settingRupees(self::FRANCHISE_LIMIT_KEY),
            default => null,
        };
    }

    private function needsDualApproval(float $amount): bool
    {
        $threshold = $this->settingRupees(self::DUAL_APPROVAL_KEY);

        return $threshold !== null && $amount > $threshold;
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

    /** May this user ask for (or give) a waiver on this booking at all? */
    public function canWaive(User $user, Booking $booking): bool
    {
        return $this->levelOf($user, $booking->franchise_id) !== null;
    }

    /** Would a waiver of $amount by this user apply at once (own limit covers it, no second approval needed)? */
    public function appliesAtOnce(User $user, ?int $franchiseId, float $amount): bool
    {
        return ! $this->needsDualApproval($amount) && $this->covers($this->levelOf($user, $franchiseId), $amount);
    }

    private function covers(?int $level, float $amount): bool
    {
        if ($level === null) {
            return false;
        }
        $limit = $this->limit($level);

        return $limit !== null && $amount <= $limit;
    }

    /** Waivers this user may see: Super Admin / global = all; franchise / city / country grants = their franchises. */
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

    /** 'approve' | 'retry' | null for this user on this waiver. UI only; every action re-checks. */
    public function availableAction(ChargeWaiver $w, User $user): ?string
    {
        $level = $this->levelOf($user, $w->franchise_id);

        return match ($w->status) {
            ChargeWaiver::AWAITING => ($user->id !== $w->requested_by_id && $this->covers($level, (float) $w->amount)) ? 'approve' : null,
            ChargeWaiver::FAILED => $this->covers($level, (float) $w->amount) ? 'retry' : null,
            default => null,
        };
    }

    // ============================== request ==============================

    /**
     * Ask for a waiver. Applies at once when the requester's own limit covers it and no second approval is needed;
     * otherwise it waits for approval. A cancel_fee waiver is requested AFTER the cancellation (the fee is already on
     * the booking); the other two before anything changes.
     *
     * @return array{ok: bool, message: string, waiver?: ChargeWaiver}
     */
    public function request(Booking $booking, string $kind, float $amount, User $actor, string $reason, ?int $cancellationRequestId = null, ?array $payload = null): array
    {
        $reason = trim($reason);
        $amount = round($amount, 2);

        if (! array_key_exists($kind, ChargeWaiver::KINDS)) {
            return $this->no('Unknown waiver type.');
        }
        if ($this->levelOf($actor, $booking->franchise_id) === null) {
            return $this->no('You do not have permission to waive charges on this booking.');
        }
        if (mb_strlen($reason) < 3) {
            return $this->no('Give a reason for the waiver — it is logged.');
        }
        if ($amount <= 0) {
            return $this->no('There is no charge to waive.');
        }

        return DB::transaction(function () use ($booking, $kind, $amount, $actor, $reason, $cancellationRequestId, $payload) {
            Booking::lockForUpdate()->findOrFail($booking->id);

            $open = ChargeWaiver::where('booking_id', $booking->id)->where('kind', $kind)
                ->whereIn('status', [ChargeWaiver::AWAITING, ChargeWaiver::FAILED])
                ->when($cancellationRequestId, fn ($q) => $q->where('cancellation_request_id', $cancellationRequestId))
                ->exists();
            if ($open) {
                return $this->no('A waiver for this charge is already waiting in the Charge Waivers queue.');
            }

            $w = ChargeWaiver::create([
                'booking_id' => $booking->id,
                'franchise_id' => $booking->franchise_id,
                'kind' => $kind,
                'cancellation_request_id' => $cancellationRequestId,
                'amount' => $amount,
                'payload' => $payload,
                'status' => ChargeWaiver::AWAITING,
                'requested_by_id' => $actor->id,
                'request_reason' => mb_substr($reason, 0, 500),
                'requested_at' => now(),
            ]);

            ActivityLogger::logModel($actor, $w, "Charge waiver requested (#{$w->id}, {$kind})", ['booking' => $booking->code, 'amount' => $amount, 'reason' => $reason]);

            if ($this->appliesAtOnce($actor, $booking->franchise_id, $amount)) {
                $w->approved_by_id = $actor->id;
                $w->approval_reason = $reason;
                $w->approved_at = now();

                return $this->execute($w, $actor) + ['waiver' => $w];
            }

            return ['ok' => true, 'message' => '₹'.number_format($amount, 2).' waiver sent for approval. Nothing changes until a different user with a sufficient limit approves it in Charge Waivers.', 'waiver' => $w];
        });
    }

    // ============================== approve / reject / retry ==============================

    /** @return array{ok: bool, message: string} */
    public function approve(ChargeWaiver $waiver, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($waiver, $actor, $reason) {
            $w = ChargeWaiver::whereKey($waiver->id)->lockForUpdate()->firstOrFail();

            if ($w->status !== ChargeWaiver::AWAITING) {
                return $this->no('This waiver is not awaiting approval.');
            }
            if ($actor->id === $w->requested_by_id) {
                return $this->no('You cannot approve your own request. A different user must approve it.');
            }
            if (! $this->covers($this->levelOf($actor, $w->franchise_id), (float) $w->amount)) {
                return $this->no('You are not permitted to approve this waiver, or it is above your limit.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $w->approved_by_id = $actor->id;
            $w->approval_reason = $reason;
            $w->approved_at = now();

            ActivityLogger::logModel($actor, $w, "Charge waiver approved (#{$w->id})", ['amount' => (float) $w->amount, 'reason' => $reason, 'requested_by_id' => $w->requested_by_id]);

            return $this->execute($w, $actor);
        });
    }

    /** @return array{ok: bool, message: string} */
    public function reject(ChargeWaiver $waiver, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($waiver, $actor, $reason) {
            $w = ChargeWaiver::whereKey($waiver->id)->lockForUpdate()->firstOrFail();

            if ($w->status !== ChargeWaiver::AWAITING) {
                return $this->no('This waiver is not awaiting approval.');
            }
            if ($actor->id === $w->requested_by_id || ! $this->covers($this->levelOf($actor, $w->franchise_id), (float) $w->amount)) {
                return $this->no('You cannot reject this request (permission, own request, or above your limit).');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $w->update(['status' => ChargeWaiver::REJECTED, 'rejected_by_id' => $actor->id, 'rejection_reason' => $reason, 'rejected_at' => now()]);
            ActivityLogger::logModel($actor, $w, "Charge waiver rejected (#{$w->id})", ['reason' => $reason, 'requested_by_id' => $w->requested_by_id]);

            return ['ok' => true, 'message' => 'Waiver rejected. The customer still owes the charge.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function retry(ChargeWaiver $waiver, User $actor): array
    {
        return DB::transaction(function () use ($waiver, $actor) {
            $w = ChargeWaiver::whereKey($waiver->id)->lockForUpdate()->firstOrFail();

            if ($w->status !== ChargeWaiver::FAILED) {
                return $this->no('Only a failed waiver can be retried.');
            }
            if (! $this->covers($this->levelOf($actor, $w->franchise_id), (float) $w->amount)) {
                return $this->no('You are not permitted to retry this waiver, or it is above your limit.');
            }

            ActivityLogger::logModel($actor, $w, "Charge waiver retry (#{$w->id})");

            return $this->execute($w, $actor);
        });
    }

    // ============================== applying ==============================

    /** Runs inside the caller's transaction with $w locked. A failure is recorded, not thrown, so that state commits. */
    private function execute(ChargeWaiver $w, User $actor): array
    {
        $requester = User::find($w->requested_by_id) ?? $actor;
        $note = $w->request_reason.($w->approved_by_id && $w->approved_by_id !== $w->requested_by_id ? " (approved by #{$w->approved_by_id}: {$w->approval_reason})" : '');

        try {
            match ($w->kind) {
                'cancellation_request' => app(WaiveCancellationChargeAction::class)->execute((int) $w->cancellation_request_id, $requester, $note),
                'interim_reduction' => app(ResolveInterimDisputeAction::class)->execute(
                    $w->booking_id, $requester, (string) ($w->payload['resolution'] ?? $w->request_reason), (float) $w->payload['amount'],
                ),
                'cancel_fee' => $this->waiveCancelFee($w),
            };
        } catch (\Throwable $e) {
            $w->status = ChargeWaiver::FAILED;
            $w->failure_message = mb_substr($e->getMessage(), 0, 1000);
            $w->save();

            ActivityLogger::logModel($actor, $w, "Charge waiver FAILED (#{$w->id})", ['error' => $e->getMessage()]);

            return $this->no('The waiver could not be applied: '.$e->getMessage());
        }

        $w->status = ChargeWaiver::APPLIED;
        $w->failure_message = null;
        $w->save();

        ActivityLogger::logModel($actor, $w, "Charge waiver applied (#{$w->id}, {$w->kind})", [
            'amount' => (float) $w->amount, 'requested_by_id' => $w->requested_by_id, 'approved_by_id' => $w->approved_by_id,
        ]);

        return ['ok' => true, 'message' => '₹'.number_format((float) $w->amount, 2).' waived.'];
    }

    /**
     * The fee an admin cancellation kept: zero it on the booking and return whatever was kept from the customer's
     * payment, the way it came (gateway refund for online, wallet ledger for wallet). Nothing was kept from a cash or
     * unpaid booking, so only the fee is cleared. Idempotent: a fee already at zero is a no-op.
     */
    private function waiveCancelFee(ChargeWaiver $w): void
    {
        $booking = Booking::with('customer')->lockForUpdate()->findOrFail($w->booking_id);
        $fee = round((float) $booking->cancellation_fee, 2);
        if ($fee <= 0) {
            return;
        }

        $payment = Payment::where('booking_id', $booking->id)->where('purpose', 'booking')->where('status', 'captured')->latest('id')->first()
            ?? Payment::where('booking_id', $booking->id)->where('purpose', 'booking')->where('status', 'refunded')->latest('id')->first();
        $kept = $payment ? round(max(0, (float) $payment->amount - (float) $payment->refunded_amount), 2) : 0.0;
        $return = round(min($fee, $kept), 2);

        if ($return > 0) {
            if ($payment->gateway === 'wallet') {
                $this->wallet->credit(
                    $booking->customer,
                    $return,
                    reason: "Refund of cancellation fee waived — booking {$booking->code}",
                    ref: "booking:{$booking->id}:wallet-refund:fee-waiver-{$w->id}",
                );
            } else {
                $this->gateway->refund($payment->gateway_payment_id, $return, "Cancellation fee waived — booking {$booking->code}");
            }
            $payment->refunded_amount = round((float) $payment->refunded_amount + $return, 2);
            $payment->status = 'refunded';
            $payment->save();
            $booking->payment_status = (float) $payment->refunded_amount >= (float) $payment->amount ? 'refunded' : 'partially_refunded';
        }

        $booking->update([
            'cancellation_fee' => 0,
            'cancellation_fee_basis' => ($booking->cancellation_fee_basis ?? []) + ['waived_by_waiver' => $w->id, 'waived_fee' => $fee, 'returned' => $return],
        ]);
        $booking->statusHistory()->create([
            'status' => $booking->status,
            'changed_by' => $w->approved_by_id,
            'note' => "Cancellation fee {$fee} waived (waiver #{$w->id})".($return > 0 ? "; {$return} returned to the customer" : ''),
            'changed_at' => now(),
        ]);
    }

    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    // ============================== escalation ==============================

    /** The lowest level whose configured limit covers this waiver. */
    public function handlerLevel(ChargeWaiver $w): int
    {
        $franchiseLimit = $this->limit(self::LEVEL_FRANCHISE);
        $hqLimit = $this->limit(self::LEVEL_HQ);
        $amount = (float) $w->amount;

        if ($w->franchise_id && $franchiseLimit !== null && $amount <= $franchiseLimit) {
            return self::LEVEL_FRANCHISE;
        }
        if ($hqLimit !== null && $amount <= $hqLimit) {
            return self::LEVEL_HQ;
        }

        return self::LEVEL_SUPER;
    }

    /** One level up per elapsed interval (franchise -> HQ -> Super Admin), once per level. @return int escalations recorded */
    public function escalateOverdue(): int
    {
        $hours = $this->escalateAfterHours();
        if ($hours === null) {
            return 0;
        }

        $raised = 0;

        ChargeWaiver::query()
            ->where('status', ChargeWaiver::AWAITING)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($hours, &$raised) {
                foreach ($rows as $w) {
                    $handler = $this->handlerLevel($w);
                    $steps = intdiv((int) $w->requested_at->diffInHours(now()), $hours);
                    $target = min(self::LEVEL_SUPER, $handler + $steps);

                    if ($target <= $handler || $target <= $w->escalation_level) {
                        continue;
                    }

                    $w->update(['escalation_level' => $target, 'last_escalated_at' => now()]);
                    ActivityLogger::logModel(null, $w, "Charge waiver #{$w->id} escalated to level {$target}", ['hours' => $hours]);
                    app(AdminOpsAlertService::class)->chargeWaiverEscalation($w, $target);
                    $raised++;
                }
            });

        return $raised;
    }
}
