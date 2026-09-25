<?php

namespace App\Support;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D5. Which wallet movements a FROZEN
 * wallet still accepts, decided by the row's source (WalletSourceLabel key).
 * Enforced inside WalletService::applyTransaction() under the wallet row
 * lock, so no caller can route around it.
 *
 * Blocked while frozen:
 *   - every debit the holder initiates (paying from wallet, payouts, tips,
 *     admin debits);
 *   - discretionary credits: loyalty redemption, referral reward, campaign
 *     reward, admin credit.
 * Still allowed while frozen (and surfaced in Earnings Control monitoring):
 *   - money OWED to the holder: refunds, a top-up whose gateway payment was
 *     already captured (new top-ups are refused at request time), a returned
 *     payout, earned job income, franchise share, compensation, tips
 *     received — refusing these would silently lose real money;
 *   - platform recoveries FROM the holder: referral clawback, cash-commission
 *     settlement.
 */
final class WalletFreezePolicy
{
    /** Credits a frozen wallet refuses. */
    private const BLOCKED_CREDITS = ['loyalty_redemption', 'referral_reward', 'campaign_reward', 'admin_adjustment'];

    /** Debits a frozen wallet still allows. */
    private const ALLOWED_DEBITS = ['referral_clawback', 'cash_commission'];

    /** Credits that land on a frozen wallet and must be shown in monitoring. */
    public const MONITORED_CREDITS = ['refund', 'topup', 'payout_reversal'];

    public static function blocks(bool $isCredit, ?string $ref): bool
    {
        $key = WalletSourceLabel::keyFor($ref);

        return $isCredit
            ? in_array($key, self::BLOCKED_CREDITS, true)
            : ! in_array($key, self::ALLOWED_DEBITS, true);
    }
}
