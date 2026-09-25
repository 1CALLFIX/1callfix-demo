<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\User;
use App\Notifications\LoyaltyPointsNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Loyalty\LoyaltyFifoLedger;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use App\Support\EarningsSettings;
use App\Support\SuperAdminGate;

/**
 * loyalty_points is a ledger, same shape as wallet_transactions -- each row
 * is one earn/redeem/expiry event. Points themselves are NOT money; redeem()
 * is the one place they turn into real Rupees, and that conversion is a
 * WalletService::credit() call (the existing, only financial ledger) --
 * there is no second "loyalty wallet". Every earn is idempotent per (user,
 * booking, reason), mirroring CommissionService::applyForBooking()'s own
 * idempotency check.
 *
 * REF 1CF-PROMPT-20260925-EARN3 (D2) — the balance is FIFO lot accounting
 * (App\Services\Loyalty\LoyaltyFifoLedger), not the old "live earns + every
 * negative row" SUM(), which went negative once an already-redeemed earn row
 * lapsed. D1 — only customers can redeem.
 */
class LoyaltyService
{
    public const REDEEM_UNAVAILABLE = 'Loyalty redemption is currently unavailable.';

    public function __construct(private WalletService $walletService)
    {
    }

    public function earn(User $user, int $points, string $reason, ?Booking $booking = null, array $scope = [], ?string $ref = null): ?LoyaltyPoint
    {
        if ($points <= 0) {
            return null;
        }

        if ($booking && LoyaltyPoint::where('user_id', $user->id)->where('booking_id', $booking->id)->where('reason', $reason)->exists()) {
            return null; // already awarded for this exact booking+reason -- safe to call more than once
        }

        if ($ref !== null && LoyaltyPoint::where('ref', $ref)->exists()) {
            return null; // same idempotency key already written
        }

        // Rule of Law: the expiry policy must be configured before any point
        // is issued (0 = never expires, set explicitly). Unset = the loyalty
        // program is not configured, so nothing is issued — thrown, not
        // silently skipped, so a caller that awards points (a campaign) sees
        // the failure. CompleteBookingAction / ReferralService check first.
        $expiryDays = EarningsSettings::integer('loyalty.points_expiry_days', $scope);
        if ($expiryDays === null) {
            throw new \RuntimeException('Loyalty points expiry (loyalty.points_expiry_days) is not configured; no points can be issued.');
        }

        $entry = LoyaltyPoint::create([
            'user_id' => $user->id,
            'points' => $points,
            'reason' => $reason,
            'booking_id' => $booking?->id,
            'ref' => $ref,
            'expires_at' => $expiryDays > 0 ? now()->addDays($expiryDays) : null,
        ]);

        $channels = ChannelResolver::resolve($scope);
        $user->notify(new LoyaltyPointsNotification('earned', $points, $this->balance($user), $channels));

        return $entry;
    }

    /** Live FIFO balance — correct whether or not the expiry job has run yet, never negative. */
    public function balance(User $user): int
    {
        return $this->balanceAt($user, now());
    }

    public function balanceAt(User $user, CarbonInterface $at): int
    {
        return LoyaltyFifoLedger::compute($this->rows($user), $at)['balance'];
    }

    /**
     * Customer-facing totals. `expired` counts both materialised expiry rows
     * and lapsed-but-not-yet-materialised points, so it never waits on the job.
     *
     * @return array{available: int, earned: int, redeemed: int, expired: int}
     */
    public function summary(User $user): array
    {
        $rows = $this->rows($user);
        $fifo = LoyaltyFifoLedger::compute($rows, now());

        return [
            'available' => $fifo['balance'],
            'earned' => $fifo['earned'],
            'redeemed' => (int) -$rows->where('reason', 'redeemed')->sum('points'),
            'expired' => $fifo['expired_materialised'] + $fifo['expired_pending'],
        ];
    }

    /**
     * Converts points to a real wallet credit at the configured rate.
     * Throws on insufficient balance or below the configured minimum --
     * enforced here, not just in a UI form, so a direct API call can't
     * bypass it.
     *
     * D1 — CUSTOMERS ONLY. A provider's points must never reach a wallet that
     * PayoutService can pay out in cash; refused here (not only in the
     * controller) so every current and future entry point inherits it.
     *
     * Phase 15 race fix kept: the balance check runs inside the transaction,
     * after locking every ledger row this user's balance is computed from.
     */
    public function redeem(User $user, int $points, array $scope = []): array
    {
        if ($user->role !== 'customer') {
            throw new AuthorizationException('Only customers can redeem loyalty points.');
        }

        if ($points <= 0) {
            throw new \InvalidArgumentException('Redemption points must be positive.');
        }

        // Rule of Law: redemption needs its switch ON and both its rate and
        // its minimum configured (0 = no minimum, set explicitly). Anything
        // unset = redemption OFF, never an in-code default.
        $minRedemption = EarningsSettings::integer('loyalty.min_redemption_points', $scope);
        $pointsPerRupee = EarningsSettings::integer('loyalty.points_per_rupee_redemption', $scope);
        if (! EarningsSettings::on('loyalty.redeem_enabled', $scope) || $minRedemption === null || $pointsPerRupee === null || $pointsPerRupee < 1) {
            throw new \RuntimeException(self::REDEEM_UNAVAILABLE);
        }

        if ($points < $minRedemption) {
            throw new \RuntimeException("Minimum redemption is {$minRedemption} points.");
        }

        // EARN3 D5 — a frozen wallet takes no redemption credit; refused
        // before any points row is written (the ledger would refuse too).
        $this->walletService->assertNotFrozen($user);

        $rupees = round($points / $pointsPerRupee, 2);

        return DB::transaction(function () use ($user, $points, $rupees, $scope) {
            $balance = $this->lockedBalance($user);
            if ($points > $balance) {
                throw new \RuntimeException("Insufficient points balance: has {$balance}, requested {$points}.");
            }

            LoyaltyPoint::create([
                'user_id' => $user->id,
                'points' => -$points,
                'reason' => 'redeemed',
            ]);

            $this->walletService->credit(
                $user,
                $rupees,
                reason: "Redeemed {$points} loyalty points",
                ref: 'loyalty-redeem:'.\Illuminate\Support\Str::uuid()
            );

            $channels = ChannelResolver::resolve($scope);
            $user->notify(new LoyaltyPointsNotification('redeemed', $points, $this->balance($user), $channels, $rupees));

            return ['points_redeemed' => $points, 'rupees_credited' => $rupees, 'new_balance' => $this->balance($user)];
        });
    }

    /**
     * D4 — take back a points-type referral reward: min(reward, live FIFO
     * balance), never more, so the balance can never go negative. Idempotent
     * via the ref.
     *
     * @return array{taken: int, shortfall: int}
     */
    public function clawback(User $user, int $points, string $reason, string $ref): array
    {
        if ($points <= 0) {
            return ['taken' => 0, 'shortfall' => 0];
        }

        return DB::transaction(function () use ($user, $points, $reason, $ref) {
            $existing = LoyaltyPoint::where('ref', $ref)->first();
            if ($existing) {
                $taken = (int) -$existing->points;

                return ['taken' => $taken, 'shortfall' => $points - $taken];
            }

            $take = min($points, $this->lockedBalance($user));

            if ($take > 0) {
                LoyaltyPoint::create(['user_id' => $user->id, 'points' => -$take, 'reason' => $reason, 'ref' => $ref]);
            }

            return ['taken' => $take, 'shortfall' => $points - $take];
        });
    }

    /**
     * D2 — materialise every lapsed, still-unconsumed lot as an `expired`
     * row (ref loyalty-expire:{lotId}), oldest first. Per-user locked
     * transaction; the unique ref is the backstop against overlapping runs.
     *
     * @return array{users: int, rows: int, points: int}
     */
    public function expireLapsedPoints(): array
    {
        $now = now();
        $result = ['users' => 0, 'rows' => 0, 'points' => 0];

        $userIds = LoyaltyPoint::query()
            ->where('points', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $written = DB::transaction(function () use ($userId, $now) {
                $rows = LoyaltyPoint::where('user_id', $userId)->orderBy('id')->lockForUpdate()->get();
                $written = ['rows' => 0, 'points' => 0];

                foreach (LoyaltyFifoLedger::lapsedUnmaterialised($rows, $now) as $lotId => $remaining) {
                    $ref = LoyaltyFifoLedger::EXPIRY_REF_PREFIX.$lotId;
                    if (LoyaltyPoint::where('ref', $ref)->exists()) {
                        continue;
                    }

                    LoyaltyPoint::create([
                        'user_id' => $userId,
                        'points' => -$remaining,
                        'reason' => 'expired',
                        'ref' => $ref,
                    ]);
                    $written['rows']++;
                    $written['points'] += $remaining;
                }

                return $written;
            });

            if ($written['rows'] > 0) {
                $result['users']++;
                $result['rows'] += $written['rows'];
                $result['points'] += $written['points'];
            }
        }

        return $result;
    }

    /**
     * REF 1CF-PROMPT-20260925-EARN3 — Stage 3. Admin points correction as a
     * NEW compensating row (ref admin-adjust:{uuid}, actor_id = the admin):
     * Super Admin only, reason mandatory, capped by loyalty.admin_adjustment_max
     * (global; unset = adjustments disabled), refused on a frozen wallet. A
     * credit is a FIFO lot with the normal expiry policy; a debit consumes
     * FIFO and can never exceed the live balance. Audited.
     */
    public function adjust(User $admin, User $target, string $direction, int $points, string $reason): LoyaltyPoint
    {
        SuperAdminGate::authorize($admin);

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException('Direction must be credit or debit.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required for every adjustment.');
        }

        $max = EarningsSettings::integer('loyalty.admin_adjustment_max');
        if ($max === null) {
            throw new \RuntimeException('Points adjustments are disabled: no maximum adjustment is configured.');
        }
        if ($points <= 0) {
            throw new \RuntimeException('Adjustment points must be positive.');
        }
        if ($points > $max) {
            throw new \RuntimeException("Adjustment exceeds the configured maximum of {$max} points.");
        }

        $this->walletService->assertNotFrozen($target);

        $ref = 'admin-adjust:'.\Illuminate\Support\Str::uuid();

        $row = DB::transaction(function () use ($admin, $target, $direction, $points, $reason, $ref) {
            if ($direction === 'debit') {
                $balance = $this->lockedBalance($target);
                if ($points > $balance) {
                    throw new \RuntimeException("Insufficient points balance: has {$balance}, requested {$points}.");
                }

                return LoyaltyPoint::create([
                    'user_id' => $target->id, 'points' => -$points, 'reason' => "Admin adjustment: {$reason}",
                    'ref' => $ref, 'actor_id' => $admin->id,
                ]);
            }

            $expiryDays = EarningsSettings::integer('loyalty.points_expiry_days');
            if ($expiryDays === null) {
                throw new \RuntimeException('Loyalty points expiry (loyalty.points_expiry_days) is not configured; no points can be issued.');
            }

            return LoyaltyPoint::create([
                'user_id' => $target->id, 'points' => $points, 'reason' => "Admin adjustment: {$reason}",
                'ref' => $ref, 'actor_id' => $admin->id,
                'expires_at' => $expiryDays > 0 ? now()->addDays($expiryDays) : null,
            ]);
        });

        ActivityLogger::log($admin, 'loyalty_points', $row->id, "Admin points {$direction} of {$points} for user #{$target->id}", [
            'target_user_id' => $target->id,
            'direction' => $direction,
            'points' => $points,
            'reason' => $reason,
            'ref' => $ref,
            'balance_after' => $this->balance($target),
        ]);

        return $row;
    }

    /** Lock every ledger row the balance is computed from, then compute it. Call inside a transaction. */
    private function lockedBalance(User $user): int
    {
        $rows = LoyaltyPoint::where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();

        return LoyaltyFifoLedger::compute($rows, now())['balance'];
    }

    private function rows(User $user)
    {
        return LoyaltyPoint::where('user_id', $user->id)->orderBy('id')->get();
    }
}
