<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ReferralStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use App\Support\EarningsSettings;
use App\Support\SuperAdminGate;

/**
 * referrals is the existing table (referrer_id, referred_id, reward_amount,
 * status) -- confirmed zero prior consumers. Reward reuses the existing
 * financial rails: 'wallet' type credits via WalletService (the only
 * ledger), 'points' type credits via LoyaltyService (which itself only
 * ever turns into money through WalletService too) -- no separate
 * referral wallet/balance.
 */
class ReferralService
{
    /** EARN4 — values written to referrals.no_reward_reason. */
    public const REASON_CAP = 'referrer_cap_reached';

    public const REASON_CAP_UNSET = 'referral_cap_not_configured';

    public const REASON_FROZEN = 'referrer_wallet_frozen';

    public const REASON_BLOCKED = 'referrer_blocked';

    public const REASON_NOT_CONFIGURED = 'referral_reward_not_configured';

    public const REASON_REFEREE_FROZEN = 'referee_wallet_frozen';

    public function __construct(private WalletService $walletService, private LoyaltyService $loyaltyService)
    {
    }

    /**
     * Called from UserObserver::created() -- works regardless of WHERE the
     * user was created (a future signup API, an admin form, a test
     * fixture), same reasoning as the other observers already registered.
     * users.referred_by is expected to already hold the resolved
     * referrer's id (set by whatever created the user, after looking up
     * their referral_code) -- not the code itself.
     */
    public function createFromSignup(User $newUser): ?Referral
    {
        if (! $newUser->referred_by) {
            return null;
        }

        // EARN4 R4 — program off: no referral rows at all (capture already
        // refuses the code; this also covers any other creation path).
        if (! EarningsSettings::on('referral.enabled')) {
            return null;
        }

        $referrer = User::find($newUser->referred_by);

        if (! $referrer || $referrer->id === $newUser->id) {
            return null; // no such referrer, or a self-referral attempt
        }

        // EARN4 R3/R8 — defence in depth behind ReferralCapture: a blocked or
        // inactive referrer never gets a referral row.
        if ($referrer->referral_blocked_at !== null || $referrer->status !== 'active') {
            return null;
        }

        if (Referral::where('referred_id', $newUser->id)->exists()) {
            return null; // already referred once -- also enforced by the DB unique index
        }

        // referral.pending_expiry_days is opt-in (default null = never
        // expires) -- an admin who wants pending referrals to lapse after
        // N days can turn it on; nothing is silently invented as a
        // mandatory window. Global scope only: createFromSignup() has no
        // franchise/zone context to resolve a scoped override against (the
        // new user frequently doesn't have one yet either -- see
        // BookingFixtureHelpers::makeCustomer()'s own comment on this).
        $expiryDays = Setting::get('referral.pending_expiry_days', '', []);
        $expiresAt = $expiryDays !== '' ? now()->addDays((int) $expiryDays) : null;

        // EARN4 R5 — fraud holds. PROTECTIVE (Rule of Law #10): unset means
        // the hold is ON; only an explicit '0' switches it off.
        $holds = [];
        if (EarningsSettings::protectionOn('referral.hold_same_signup_ip')
            && $newUser->signup_ip !== null
            && in_array($newUser->signup_ip, array_filter([$referrer->signup_ip, $referrer->last_login_ip]), true)) {
            $holds[] = 'same_signup_ip';
        }
        if (EarningsSettings::protectionOn('referral.hold_same_device')
            && $newUser->signup_device_id !== null
            && in_array($newUser->signup_device_id, array_filter([$referrer->signup_device_id, $referrer->last_login_device_id]), true)) {
            $holds[] = 'same_device';
        }

        // EARN4 R3 — a valid code from a referrer already at the cap is still
        // recorded, with the reason it can earn nothing.
        $cap = EarningsSettings::integer('referral.max_per_customer');
        $atCap = $cap !== null && Referral::where('referrer_id', $referrer->id)->where('status', 'rewarded')->count() >= $cap;

        return Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $newUser->id,
            'status' => $holds ? 'held_review' : 'pending',
            'hold_reason' => $holds ? implode(',', $holds) : null,
            'no_reward_reason' => $atCap ? self::REASON_CAP : null,
            'signup_ip' => $newUser->signup_ip,
            'signup_device_id' => $newUser->signup_device_id,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Called by the ExpireReferrals scheduled command (housekeeping only —
     * qualifyFromCompletedBooking() ALSO independently re-checks status via
     * its own 'pending' guard, so a referral that's actually about to
     * qualify in the same moment this runs can never be expired out from
     * under a legitimate reward; expiring only ever removes a referral
     * from FUTURE eligibility, never reverses one already rewarded).
     *
     * @return int how many were expired, for the command's summary output
     */
    public function expirePendingReferrals(): int
    {
        return Referral::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    /**
     * Admin-driven manual fraud flag — no automatic detection (the real
     * signal thresholds are a genuine pending business decision, see
     * KNOWN_RISKS_AND_DECISIONS.md item 3). If the referral was already
     * 'rewarded', attempts a wallet clawback via the same WalletService
     * every other reward/refund path in this codebase uses — if the
     * referrer no longer has the balance (already spent it),
     * WalletService::debit() refuses to take them negative, and that
     * failure is recorded honestly in reversal_note rather than silently
     * swallowed or allowed to crash the flagging action itself.
     */
    public function flagAsFraud(Referral $referral, User $admin, string $notes): Referral
    {
        $wasRewarded = $referral->status === 'rewarded';
        $clawbackNote = null;

        // REF 1CF-PROMPT-20260925-EARN3 (D4) — a points-type reward is clawed
        // back too: min(reward, live FIFO balance), never below zero; any
        // shortfall (points already redeemed or expired) is recorded, not
        // forced.
        if ($wasRewarded && $referral->referrer && ($awarded = $this->pointsAwarded($referral)) > 0) {
            $result = $this->loyaltyService->clawback(
                $referral->referrer,
                $awarded,
                reason: 'referral_clawback',
                ref: "referral:{$referral->id}:points-clawback",
            );
            $clawbackNote = "Points reward of {$awarded} clawed back: took {$result['taken']}, shortfall {$result['shortfall']}.";
        }

        if ($wasRewarded && (float) $referral->reward_amount > 0) {
            try {
                $this->walletService->debit(
                    $referral->referrer,
                    (float) $referral->reward_amount,
                    reason: "Referral reward clawback (flagged as fraud): {$notes}",
                    ref: "referral:{$referral->id}:clawback"
                );
                $clawbackNote = "Reward of {$referral->reward_amount} clawed back from wallet.";
            } catch (\Throwable $e) {
                $clawbackNote = "Clawback attempted but failed (referrer balance insufficient): {$e->getMessage()}";
            }
        }

        // EARN4 R7 — the referee reward is clawed back under the same rules:
        // wallet → debit (never below zero; a failure is recorded), points →
        // min(reward, live FIFO balance), shortfall recorded.
        if ($wasRewarded && $referral->referee_rewarded_at !== null && $referral->referred) {
            if ((int) $referral->referee_reward_points > 0) {
                $result = $this->loyaltyService->clawback(
                    $referral->referred,
                    (int) $referral->referee_reward_points,
                    reason: 'referral_clawback',
                    ref: "referral:{$referral->id}:referee-points-clawback",
                );
                $clawbackNote = trim($clawbackNote."Referee points reward of {$referral->referee_reward_points} clawed back: took {$result['taken']}, shortfall {$result['shortfall']}.");
            } elseif ((float) $referral->referee_reward_amount > 0) {
                try {
                    $this->walletService->debit(
                        $referral->referred,
                        (float) $referral->referee_reward_amount,
                        reason: "Referee reward clawback (flagged as fraud): {$notes}",
                        ref: "referral:{$referral->id}:referee-clawback"
                    );
                    $clawbackNote = trim($clawbackNote." Referee reward of {$referral->referee_reward_amount} clawed back from wallet.");
                } catch (\Throwable $e) {
                    $clawbackNote = trim($clawbackNote." Referee clawback attempted but failed (balance insufficient): {$e->getMessage()}");
                }
            }
        }

        $referral->status = 'fraud_flagged';
        $referral->fraud_flagged_at = now();
        $referral->fraud_flagged_by = $admin->id;
        $referral->fraud_notes = $notes;
        if ($wasRewarded) {
            $referral->reversed_at = now();
            $referral->reversal_note = $clawbackNote;
        }
        $referral->save();

        return $referral->fresh();
    }

    /**
     * Points actually awarded for this referral: the ref-keyed row written
     * since EARN3, else (older rows) the referrer's 'referral_reward' row for
     * the qualifying booking.
     */
    private function pointsAwarded(Referral $referral): int
    {
        $row = LoyaltyPoint::where('ref', "referral:{$referral->id}:points-reward")->first()
            ?? ($referral->qualifying_booking_id
                ? LoyaltyPoint::where('user_id', $referral->referrer_id)
                    ->where('booking_id', $referral->qualifying_booking_id)
                    ->where('reason', 'referral_reward')
                    ->where('points', '>', 0)
                    ->first()
                : null);

        return (int) ($row?->points ?? 0);
    }

    /**
     * Called from CompleteBookingAction. Qualification condition: the
     * referred user's FIRST EVER completed booking — only that completion
     * triggers automatically. Idempotent via the 'pending' status guard and
     * the row lock in issueRewards().
     */
    public function qualifyFromCompletedBooking(Booking $booking, array $scope = []): ?Referral
    {
        $referral = Referral::where('referred_id', $booking->customer_id)->where('status', 'pending')->first();

        if (! $referral) {
            return null; // none, or held_review / rejected / already rewarded — never rewarded automatically
        }

        if (Booking::where('customer_id', $booking->customer_id)->where('status', 'completed')->count() !== 1) {
            return null; // not their first completed booking
        }

        return $this->issueRewards($referral, $booking, $scope);
    }

    /**
     * EARN4 — the ONE reward engine, shared by the automatic trigger and the
     * Super Admin release (R6). Locks the referral row, so two concurrent
     * calls can never both pay.
     *
     *   pending  → referrer reward (EARN3 rules: switch, cap, reward config,
     *              frozen wallet, blocked referrer — R8) then → rewarded.
     *   rewarded → nothing more for the referrer.
     *   Then, once rewarded, the referee reward (R7) if configured and not yet
     *   issued (referee_rewarded_at is the once-only marker).
     *
     * Every "no reward" outcome is written to no_reward_reason.
     */
    public function issueRewards(Referral $referral, Booking $booking, array $scope = []): ?Referral
    {
        return DB::transaction(function () use ($referral, $booking, $scope) {
            /** @var Referral|null $referral */
            $referral = Referral::with(['referrer', 'referred'])->lockForUpdate()->find($referral->id);

            if (! $referral || ! in_array($referral->status, ['pending', 'rewarded'], true)) {
                return null;
            }

            if (! EarningsSettings::on('referral.enabled', $scope)) {
                return null;
            }

            if ($referral->status === 'pending' && ! $this->payReferrer($referral, $booking, $scope)) {
                return null;
            }

            $this->payReferee($referral, $scope);

            return $referral->fresh();
        });
    }

    /** @return bool whether the referrer was paid (referral is now 'rewarded') */
    private function payReferrer(Referral $referral, Booking $booking, array $scope): bool
    {
        $referrer = $referral->referrer;
        if (! $referrer) {
            return false;
        }

        if ($referrer->referral_blocked_at !== null) {
            $this->recordNoReward($referral, self::REASON_BLOCKED);

            return false;
        }

        $cap = EarningsSettings::integer('referral.max_per_customer', $scope);
        if ($cap === null || Referral::where('referrer_id', $referral->referrer_id)->where('status', 'rewarded')->count() >= $cap) {
            $this->recordNoReward($referral, $cap === null ? self::REASON_CAP_UNSET : self::REASON_CAP);

            return false;
        }

        $rewardType = EarningsSettings::raw('referral.reward_type', $scope);
        $rewardValue = match ($rewardType) {
            'wallet' => EarningsSettings::number('referral.reward_amount', $scope),
            'points' => EarningsSettings::integer('referral.reward_points', $scope),
            default => null,
        };
        if ($rewardValue === null || ($rewardType === 'points' && EarningsSettings::integer('loyalty.points_expiry_days', $scope) === null)) {
            $this->recordNoReward($referral, self::REASON_NOT_CONFIGURED);

            return false;
        }

        // EARN3 D5 — a frozen referrer's wallet takes no referral credit
        // (wallet or points). Withheld and audited; the referral stays
        // pending until a Super Admin releases it (EARN4 R6).
        if ($this->walletService->isFrozen($referrer)) {
            ActivityLogger::logModel(null, $referral, "Referral reward withheld: referrer #{$referral->referrer_id} wallet is frozen", [
                'booking_id' => $booking->id, 'reward_type' => $rewardType, 'reward_value' => $rewardValue,
            ]);
            $this->recordNoReward($referral, self::REASON_FROZEN);

            return false;
        }

        if ($rewardType === 'points') {
            $this->loyaltyService->earn($referrer, (int) $rewardValue, 'referral_reward', $booking, $scope, "referral:{$referral->id}:points-reward");
            $referral->reward_amount = 0;
        } else {
            $amount = (float) $rewardValue;
            if ($amount > 0) {
                $this->walletService->credit(
                    $referrer,
                    $amount,
                    reason: "Referral reward for referring {$referral->referred?->name}",
                    ref: "referral:{$referral->id}:reward"
                );
            }
            $referral->reward_amount = $amount;
        }

        $referral->status = 'rewarded';
        $referral->qualifying_booking_id = $booking->id;
        $referral->no_reward_reason = null;
        $referral->save();

        $channels = ChannelResolver::resolve($scope);
        $referrer->notify(new ReferralStatusNotification('rewarded', $referral->fresh(), $channels));

        return true;
    }

    /**
     * EARN4 R7 — the referee's own reward, paid on the same trigger as the
     * referrer's. referral.referee_reward_* are GLOBAL keys; unset type or
     * value = no referee reward. Same freeze rule as the referrer (withheld,
     * reason recorded, releasable). Exactly once: referee_rewarded_at under
     * the row lock, plus the unique ledger ref.
     */
    private function payReferee(Referral $referral, array $scope): void
    {
        if ($referral->referee_rewarded_at !== null || $referral->status !== 'rewarded' || ! $referral->referred) {
            return;
        }

        $type = EarningsSettings::raw('referral.referee_reward_type');
        $value = match ($type) {
            'wallet' => EarningsSettings::number('referral.referee_reward_amount'),
            'points' => EarningsSettings::integer('referral.referee_reward_points'),
            default => null,
        };
        if ($value === null || $value <= 0 || ($type === 'points' && EarningsSettings::integer('loyalty.points_expiry_days', $scope) === null)) {
            return; // no referee reward configured
        }

        $referee = $referral->referred;

        if ($this->walletService->isFrozen($referee)) {
            ActivityLogger::logModel(null, $referral, "Referee reward withheld: referee #{$referee->id} wallet is frozen", [
                'reward_type' => $type, 'reward_value' => $value,
            ]);
            $this->recordNoReward($referral, self::REASON_REFEREE_FROZEN);

            return;
        }

        if ($type === 'points') {
            $this->loyaltyService->earn($referee, (int) $value, 'referral_referee_reward', null, $scope, "referral:{$referral->id}:referee-points-reward");
            $referral->referee_reward_points = (int) $value;
        } else {
            $this->walletService->credit(
                $referee,
                (float) $value,
                reason: 'Welcome reward for joining with a referral code',
                ref: "referral:{$referral->id}:referee-reward"
            );
            $referral->referee_reward_amount = (float) $value;
        }

        $referral->referee_rewarded_at = now();
        if ($referral->no_reward_reason === self::REASON_REFEREE_FROZEN) {
            $referral->no_reward_reason = null;
        }
        $referral->save();
    }

    /**
     * EARN4 R6 — Super Admin releases a held or pending referral (or the
     * withheld referee part of a rewarded one). Re-runs the normal
     * qualification against the referee's FIRST completed booking; rewards
     * are issued only if it qualifies, and never twice. Audited.
     *
     * @return array{outcome: string, referral: Referral}
     */
    public function release(Referral $referral, User $admin, string $reason): array
    {
        SuperAdminGate::authorize($admin);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($referral, $admin, $reason) {
            $referral = Referral::lockForUpdate()->findOrFail($referral->id);
            $before = $referral->status;

            if (! in_array($before, ['pending', 'held_review', 'rewarded'], true)) {
                throw new \RuntimeException("A {$before} referral cannot be released.");
            }

            if ($before === 'held_review') {
                $referral->status = 'pending';
            }
            $referral->forceFill(['reviewed_at' => now(), 'reviewed_by' => $admin->id, 'review_note' => $reason])->save();

            $booking = Booking::where('customer_id', $referral->referred_id)->where('status', 'completed')->orderBy('id')->first();
            $refereeDoneBefore = $referral->referee_rewarded_at !== null;

            if (! $booking) {
                $outcome = 'released_awaiting_first_completed_booking';
            } else {
                $bookingScope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
                $this->issueRewards($referral, $booking, $bookingScope);
                $after = $referral->fresh();
                $outcome = match (true) {
                    $before !== 'rewarded' && $after->status === 'rewarded' => 'rewarded',
                    $before === 'rewarded' && ! $refereeDoneBefore && $after->referee_rewarded_at !== null => 'referee_reward_issued',
                    $before === 'rewarded' => 'nothing_to_release',
                    default => 'no_reward: '.($after->no_reward_reason ?? 'not qualified'),
                };
            }

            $referral = $referral->fresh();
            ActivityLogger::logModel($admin, $referral, "Referral #{$referral->id} released", [
                'reason' => $reason, 'status_before' => $before, 'status_after' => $referral->status, 'outcome' => $outcome,
            ]);

            return ['outcome' => $outcome, 'referral' => $referral];
        });
    }

    /** EARN4 R6 — Super Admin rejects a held or pending referral. Final: it can never be released or rewarded. Audited. */
    public function reject(Referral $referral, User $admin, string $reason): Referral
    {
        SuperAdminGate::authorize($admin);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($referral, $admin, $reason) {
            $referral = Referral::lockForUpdate()->findOrFail($referral->id);
            $before = $referral->status;

            if (! in_array($before, ['pending', 'held_review'], true)) {
                throw new \RuntimeException("A {$before} referral cannot be rejected (use the fraud flag for a rewarded one).");
            }

            $referral->forceFill([
                'status' => 'rejected', 'reviewed_at' => now(), 'reviewed_by' => $admin->id, 'review_note' => $reason,
            ])->save();

            ActivityLogger::logModel($admin, $referral, "Referral #{$referral->id} rejected", [
                'reason' => $reason, 'status_before' => $before,
            ]);

            return $referral->fresh();
        });
    }

    /** EARN4 R8 — block a customer from the referral program (code refused at signup, no rewards as referrer). Audited. */
    public function blockUser(User $admin, User $user, string $reason): User
    {
        SuperAdminGate::authorize($admin);
        $reason = $this->requireReason($reason);

        if ($user->referral_blocked_at !== null) {
            throw new \RuntimeException('This customer is already blocked from referrals.');
        }

        $user->forceFill(['referral_blocked_at' => now(), 'referral_blocked_reason' => $reason, 'referral_blocked_by' => $admin->id])->save();
        ActivityLogger::logModel($admin, $user, "Blocked user #{$user->id} from the referral program", ['reason' => $reason]);

        return $user->fresh();
    }

    public function unblockUser(User $admin, User $user, string $reason): User
    {
        SuperAdminGate::authorize($admin);
        $reason = $this->requireReason($reason);

        if ($user->referral_blocked_at === null) {
            throw new \RuntimeException('This customer is not blocked from referrals.');
        }

        $previous = ['blocked_at' => (string) $user->referral_blocked_at, 'reason' => $user->referral_blocked_reason, 'by' => $user->referral_blocked_by];
        $user->forceFill(['referral_blocked_at' => null, 'referral_blocked_reason' => null, 'referral_blocked_by' => null])->save();
        ActivityLogger::logModel($admin, $user, "Unblocked user #{$user->id} from the referral program", ['reason' => $reason, 'previous' => $previous]);

        return $user->fresh();
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required.');
        }

        return $reason;
    }

    /** EARN4 R3 — note on the row why this referral earned nothing (last reason wins). */
    private function recordNoReward(Referral $referral, string $reason): void
    {
        if ($referral->no_reward_reason !== $reason) {
            $referral->forceFill(['no_reward_reason' => $reason])->save();
        }
    }
}
