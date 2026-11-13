<?php

namespace App\Services\Halls;

use App\Models\Franchise;
use App\Models\HallBooking;
use App\Models\HallDamageClaim;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Function halls R4 (owner 2026-11-13) — damage claims on hall deposits, under the MANUAL MONEY ACTIONS approval model
 * (CLAUDE.md), the same shape as ParcelProtectionClaimService:
 *  - permission  hall_damage.payout (Super Admin always), checked here, server-side;
 *  - scope       franchise holders act only on their franchise's claims; global / country / city holders are HQ;
 *  - limits      refund.hall_damage.franchise_limit / hq_limit (rupees, null = that level cannot approve), on the
 *                amount the hall gets (the rest is the customer's own money going back);
 *  - maker-checker  refund.hall_damage.dual_approval_above (null = off): requester and approver must differ;
 *  - escalation  refund.hall_damage.escalate_after_hours (null = off), franchise → HQ → Super Admin;
 *  - reason required, row-locked, idempotent (wallet refs), never automatic.
 * Money: the deposit was paid to the HQ gateway / ledger. Settling sends the customer's part back to where they paid
 * from and credits the hall's part to the owner's wallet (paid out with their earnings). Franchise users approve; they
 * never hold or move funds.
 */
class HallDamageClaimService
{
    public const PAYOUT = 'hall_damage.payout';

    public const LEVEL_FRANCHISE = 1;
    public const LEVEL_HQ = 2;
    public const LEVEL_SUPER = 3;

    public const FRANCHISE_LIMIT_KEY = 'refund.hall_damage.franchise_limit';
    public const HQ_LIMIT_KEY = 'refund.hall_damage.hq_limit';
    public const DUAL_APPROVAL_KEY = 'refund.hall_damage.dual_approval_above';
    public const ESCALATE_HOURS_KEY = 'refund.hall_damage.escalate_after_hours';

    public function __construct(private AuthorizationService $authz, private WalletService $wallet, private HallBookingService $halls)
    {
    }

    // ============================== the owner's report ==============================

    /** Called when the owner reports damage: the deposit is held and a claim waits in the team's queue. */
    public function open(HallBooking $booking, float $asked, string $note): HallDamageClaim
    {
        return HallDamageClaim::firstOrCreate(['hall_booking_id' => $booking->id], [
            'franchise_id' => $booking->franchise_id,
            'deposit_amount' => $booking->deposit_amount,
            'asked_amount' => round(min($asked, (float) $booking->deposit_amount), 2),
            'note' => mb_substr(trim($note), 0, 1000),
            'status' => HallDamageClaim::OPEN,
        ]);
    }

    // ============================== who may act ==============================

    private function scopeFor(?int $franchiseId): array
    {
        if (! $franchiseId) {
            return [];
        }
        $franchise = Franchise::find($franchiseId);

        return array_filter(['franchise_id' => $franchiseId, 'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id]);
    }

    /** 3 = Super Admin, 2 = HQ grant, 1 = franchise grant, null = no such permission over this franchise. */
    public function levelOf(User $user, ?int $franchiseId): ?int
    {
        if ($user->role === 'super_admin') {
            return self::LEVEL_SUPER;
        }
        $scope = $this->scopeFor($franchiseId);
        $best = null;
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', self::PAYOUT)) {
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
        return $this->authz->canAnywhere($user, self::PAYOUT);
    }

    /** Claims this user may see: Super Admin / global = all; franchise / city / country grants = their franchises. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'super_admin') {
            return $query;
        }
        $ids = [];
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', self::PAYOUT)) {
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

    private function rupees(string $key): ?float
    {
        $value = Setting::get($key);

        return ($value === null || trim((string) $value) === '') ? null : round((float) $value, 2);
    }

    public function limit(int $level): ?float
    {
        return match ($level) {
            self::LEVEL_SUPER => (float) PHP_INT_MAX,
            self::LEVEL_HQ => $this->rupees(self::HQ_LIMIT_KEY),
            self::LEVEL_FRANCHISE => $this->rupees(self::FRANCHISE_LIMIT_KEY),
            default => null,
        };
    }

    private function canCover(?int $level, float $amount): bool
    {
        $limit = $level === null ? null : $this->limit($level);

        return $limit !== null && $amount <= $limit;
    }

    private function needsDualApproval(float $amount): bool
    {
        $threshold = $this->rupees(self::DUAL_APPROVAL_KEY);

        return $threshold !== null && $amount > $threshold;
    }

    /** 'decide' | 'approve' | 'retry' | null — UI only; every action re-checks. */
    public function availableAction(HallDamageClaim $c, User $user): ?string
    {
        $level = $this->levelOf($user, $c->franchise_id);
        if ($level === null) {
            return null;
        }

        return match ($c->status) {
            HallDamageClaim::OPEN => 'decide',
            HallDamageClaim::AWAITING_APPROVAL => ($user->id !== $c->requested_by_id && $this->canCover($level, (float) $c->hall_amount)) ? 'approve' : null,
            HallDamageClaim::FAILED => $this->canCover($level, (float) $c->hall_amount) ? 'retry' : null,
            default => null,
        };
    }

    // ============================== decide (maker) / approve (checker) ==============================

    /**
     * The team says how much of the deposit the hall gets (0 = nothing, the whole deposit goes back). Settles at once
     * when no second approval is needed and the user's limit covers it; otherwise waits for a different approver.
     *
     * @return array{ok: bool, message: string}
     */
    public function decide(HallDamageClaim $claim, User $actor, float $hallAmount, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($claim, $actor, $hallAmount, $reason) {
            $c = HallDamageClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== HallDamageClaim::OPEN) {
                return $this->no('This claim is not waiting for a decision.');
            }
            $level = $this->levelOf($actor, $c->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to decide this claim.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }
            $hallAmount = round($hallAmount, 2);
            if ($hallAmount < 0 || $hallAmount > (float) $c->deposit_amount) {
                return $this->no('The hall can get between ₹0 and the deposit (₹'.number_format((float) $c->deposit_amount).').');
            }

            $c->fill(['hall_amount' => $hallAmount, 'requested_by_id' => $actor->id, 'request_reason' => $reason, 'requested_at' => now()]);
            ActivityLogger::logModel($actor, $c, "Hall damage claim decided (#{$c->id})", ['hall_amount' => $hallAmount, 'reason' => $reason, 'level' => $level]);

            if ($hallAmount <= 0 || (! $this->needsDualApproval($hallAmount) && $this->canCover($level, $hallAmount))) {
                return $this->execute($c, $actor);
            }

            $c->status = HallDamageClaim::AWAITING_APPROVAL;
            $c->save();

            return ['ok' => true, 'message' => 'Recorded. It now needs approval from a different person with a high enough limit.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function approve(HallDamageClaim $claim, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $c = HallDamageClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== HallDamageClaim::AWAITING_APPROVAL) {
                return $this->no('This claim is not waiting for approval.');
            }
            $level = $this->levelOf($actor, $c->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to approve this claim.');
            }
            if ($actor->id === $c->requested_by_id) {
                return $this->no('You cannot approve your own decision. A different person must approve it.');
            }
            if (! $this->canCover($level, (float) $c->hall_amount)) {
                return $this->no('Your approval limit does not cover this amount.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }
            $c->fill(['approved_by_id' => $actor->id, 'approval_reason' => $reason, 'approved_at' => now()]);
            ActivityLogger::logModel($actor, $c, "Hall damage claim approved (#{$c->id})", ['hall_amount' => $c->hall_amount, 'reason' => $reason, 'requested_by_id' => $c->requested_by_id]);

            return $this->execute($c, $actor);
        });
    }

    /** @return array{ok: bool, message: string} */
    public function reject(HallDamageClaim $claim, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $c = HallDamageClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== HallDamageClaim::AWAITING_APPROVAL) {
                return $this->no('This claim is not waiting for approval.');
            }
            $level = $this->levelOf($actor, $c->franchise_id);
            if ($level === null || $actor->id === $c->requested_by_id || ! $this->canCover($level, (float) $c->hall_amount)) {
                return $this->no('You cannot reject this decision (permission, your own decision, or above your limit).');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }
            ActivityLogger::logModel($actor, $c, "Hall damage claim decision rejected (#{$c->id})", ['reason' => $reason, 'requested_by_id' => $c->requested_by_id]);
            $c->fill(['status' => HallDamageClaim::OPEN, 'hall_amount' => null, 'requested_by_id' => null, 'request_reason' => null,
                'requested_at' => null, 'rejected_at' => now(), 'escalation_level' => 0, 'last_escalated_at' => null])->save();

            return ['ok' => true, 'message' => 'Rejected. The claim is back waiting for a new decision.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function retry(HallDamageClaim $claim, User $actor): array
    {
        return DB::transaction(function () use ($claim, $actor) {
            $c = HallDamageClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== HallDamageClaim::FAILED) {
                return $this->no('Only a failed payment can be retried.');
            }
            if (! $this->canCover($this->levelOf($actor, $c->franchise_id), (float) $c->hall_amount)) {
                return $this->no('You are not permitted to retry this, or it is above your limit.');
            }
            ActivityLogger::logModel($actor, $c, "Hall damage claim retry (#{$c->id})");

            return $this->execute($c, $actor);
        });
    }

    /**
     * Inside the caller's transaction with $c locked. Customer's part back first (the gateway can fail), then the hall's
     * part to the owner's wallet. Each half is done once (booking deposit status / wallet ref), so a retry finishes the
     * rest. A failure is recorded, not thrown, so that state commits.
     */
    private function execute(HallDamageClaim $c, User $actor): array
    {
        $booking = HallBooking::with(['customer', 'hall.provider.user'])->lockForUpdate()->findOrFail($c->hall_booking_id);
        $hallAmount = round((float) $c->hall_amount, 2);
        $back = round((float) $c->deposit_amount - $hallAmount, 2);
        $ref = "hall_booking:{$booking->id}:damage-claim-{$c->id}";

        try {
            if ($booking->deposit_status === 'claimed') {
                $sent = $back > 0 ? $this->halls->refundPart($booking, $back, 'deposit after damage check', 'deposit') : 0.0;
                $booking->forceFill(['deposit_status' => 'settled', 'deposit_returned_at' => now(),
                    'refunded_amount' => round((float) $booking->refunded_amount + $sent, 2)])->save();
            }
            $owner = $booking->hall?->provider?->user;
            if ($hallAmount > 0 && $owner && ! WalletTransaction::where('ref', $ref)->exists()) {
                $this->wallet->credit($owner, $hallAmount, reason: "Damage claim for hall booking {$booking->code}", ref: $ref, actorId: $actor->id);
            }
        } catch (\Throwable $e) {
            $c->fill(['status' => HallDamageClaim::FAILED, 'failure_message' => mb_substr($e->getMessage(), 0, 1000)])->save();
            ActivityLogger::logModel($actor, $c, "Hall damage claim payment FAILED (#{$c->id})", ['error' => $e->getMessage()]);

            return $this->no('The money could not be moved. Nothing more was paid; you can retry. Details are in the activity log.');
        }

        $c->fill(['status' => HallDamageClaim::SETTLED, 'settled_at' => now(), 'failure_message' => null])->save();
        ActivityLogger::logModel($actor, $c, "Hall damage claim settled (#{$c->id})", [
            'hall_amount' => $hallAmount, 'customer_back' => $back, 'requested_by_id' => $c->requested_by_id, 'approved_by_id' => $c->approved_by_id,
        ]);
        DB::afterCommit(fn () => $this->halls->notifyDamageSettled($booking->fresh(['hall.provider.user', 'customer']), $hallAmount, $back));

        return ['ok' => true, 'message' => 'Done. The hall gets ₹'.number_format($hallAmount, 2).'; ₹'.number_format($back, 2).' goes back to the customer.'];
    }

    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    // ============================== escalation ==============================

    public function escalateAfterHours(): ?int
    {
        $value = Setting::get(self::ESCALATE_HOURS_KEY);

        return ($value === null || trim((string) $value) === '' || (int) $value < 1) ? null : (int) $value;
    }

    public function handlerLevel(HallDamageClaim $c): int
    {
        $franchiseLimit = $this->limit(self::LEVEL_FRANCHISE);
        $hqLimit = $this->limit(self::LEVEL_HQ);
        $amount = (float) ($c->hall_amount ?? $c->asked_amount);
        if ($c->franchise_id && $franchiseLimit !== null && $amount <= $franchiseLimit) {
            return self::LEVEL_FRANCHISE;
        }

        return ($hqLimit !== null && $amount <= $hqLimit) ? self::LEVEL_HQ : self::LEVEL_SUPER;
    }

    /** One level up per elapsed interval, once per level; audit-logged. @return int escalations recorded */
    public function escalateOverdue(): int
    {
        $hours = $this->escalateAfterHours();
        if ($hours === null) {
            return 0;
        }
        $raised = 0;
        HallDamageClaim::whereIn('status', HallDamageClaim::WAITING)->orderBy('id')->chunkById(200, function ($rows) use ($hours, &$raised) {
            foreach ($rows as $c) {
                $handler = $this->handlerLevel($c);
                $target = min(self::LEVEL_SUPER, $handler + intdiv((int) $c->clockStartedAt()->diffInHours(now()), $hours));
                if ($target <= $handler || $target <= $c->escalation_level) {
                    continue;
                }
                $c->update(['escalation_level' => $target, 'last_escalated_at' => now()]);
                ActivityLogger::logModel(null, $c, "Hall damage claim #{$c->id} escalated to level {$target}", ['hours' => $hours]);
                $raised++;
            }
        });

        return $raised;
    }
}
