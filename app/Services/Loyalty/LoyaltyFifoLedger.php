<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyPoint;
use Carbon\CarbonInterface;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D2. The one place a points balance is
 * computed. Pure: takes a user's ledger rows and a clock time, returns the
 * FIFO lot state — no queries, no writes.
 *
 * Model:
 *   - every positive row is a LOT (earn, referral reward, campaign reward,
 *     admin credit) carrying its own expires_at;
 *   - every negative row that is not an expiry row is a CONSUMPTION
 *     (redemption, referral clawback, admin debit). Replayed in ledger order,
 *     it draws from the oldest lot that was still live at the consumption's
 *     own created_at — an already-lapsed lot can never be spent;
 *   - an expiry row (`ref = loyalty-expire:{lotId}`) zeroes exactly the lot it
 *     names — it is the scheduled job materialising a lapse that the live
 *     balance already reflected.
 *
 * Balance at T = Σ remaining of lots still live at T. Every remaining is
 * clamped at ≥ 0, so the balance can never be negative — including for a
 * legacy ledger written by the old formula (a consumption with no live lot
 * left to draw from is reported as `overdrawn`, never subtracted twice).
 * Once every lapse is materialised, SUM(points) equals this balance.
 */
final class LoyaltyFifoLedger
{
    public const EXPIRY_REF_PREFIX = 'loyalty-expire:';

    /**
     * @param  iterable<LoyaltyPoint>  $rows  one user's rows (any order)
     * @return array{
     *     balance: int,
     *     earned: int,
     *     consumed: int,
     *     expired_materialised: int,
     *     expired_pending: int,
     *     overdrawn: int,
     *     lots: array<int, array{remaining: int, expires_at: ?CarbonInterface, materialised: bool}>
     * }
     */
    public static function compute(iterable $rows, CarbonInterface $at): array
    {
        $rows = collect($rows)->sortBy('id')->values();

        $lots = [];
        $earned = 0;
        $consumed = 0;
        $expiredMaterialised = 0;
        $overdrawn = 0;

        foreach ($rows as $row) {
            $points = (int) $row->points;

            if ($points > 0) {
                $lots[$row->id] = ['remaining' => $points, 'expires_at' => $row->expires_at, 'materialised' => false];
                $earned += $points;

                continue;
            }

            if ($points === 0) {
                continue;
            }

            $need = -$points;

            if (self::isExpiryRow($row)) {
                $lotId = (int) substr((string) $row->ref, strlen(self::EXPIRY_REF_PREFIX));
                if (isset($lots[$lotId])) {
                    $take = min($need, $lots[$lotId]['remaining']);
                    $lots[$lotId]['remaining'] -= $take;
                    $lots[$lotId]['materialised'] = true;
                }
                $expiredMaterialised += $need;

                continue;
            }

            $consumed += $need;
            $when = $row->created_at;

            foreach ($lots as $id => $lot) {
                if ($need === 0) {
                    break;
                }
                if ($lot['remaining'] === 0 || ! self::liveAt($lot['expires_at'], $when)) {
                    continue;
                }
                $take = min($need, $lot['remaining']);
                $lots[$id]['remaining'] -= $take;
                $need -= $take;
            }

            $overdrawn += $need;
        }

        $balance = 0;
        $expiredPending = 0;
        foreach ($lots as $lot) {
            if (self::liveAt($lot['expires_at'], $at)) {
                $balance += $lot['remaining'];
            } elseif (! $lot['materialised']) {
                $expiredPending += $lot['remaining'];
            }
        }

        return [
            'balance' => $balance,
            'earned' => $earned,
            'consumed' => $consumed,
            'expired_materialised' => $expiredMaterialised,
            'expired_pending' => $expiredPending,
            'overdrawn' => $overdrawn,
            'lots' => $lots,
        ];
    }

    /**
     * Lots that have lapsed by $at, still hold points, and have no expiry row
     * yet — exactly what the scheduled expiry command must write.
     *
     * @return array<int, int> lotId => points to expire
     */
    public static function lapsedUnmaterialised(iterable $rows, CarbonInterface $at): array
    {
        $out = [];
        foreach (self::compute($rows, $at)['lots'] as $id => $lot) {
            if (! $lot['materialised'] && $lot['remaining'] > 0 && ! self::liveAt($lot['expires_at'], $at)) {
                $out[$id] = $lot['remaining'];
            }
        }

        return $out;
    }

    /**
     * The pre-EARN3 formula, kept ONLY so loyalty:balance-audit can show where
     * it disagreed. Expiry rows are skipped: the old code never wrote any (it
     * simply dropped a lapsed lot from the sum), so counting them here would
     * double-subtract every lapse and flag every healthy user.
     */
    public static function legacyBalance(iterable $rows, CarbonInterface $at): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            if (self::isExpiryRow($row)) {
                continue;
            }
            $points = (int) $row->points;
            if ($points < 0 || $row->expires_at === null || $row->expires_at->gt($at)) {
                $sum += $points;
            }
        }

        return $sum;
    }

    public static function isExpiryRow(LoyaltyPoint $row): bool
    {
        return is_string($row->ref) && str_starts_with($row->ref, self::EXPIRY_REF_PREFIX);
    }

    private static function liveAt(?CarbonInterface $expiresAt, ?CarbonInterface $at): bool
    {
        return $expiresAt === null || $at === null || $expiresAt->gt($at);
    }
}
