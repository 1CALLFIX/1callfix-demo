<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;
use App\Support\EarningsSettings;

/**
 * The customer-initiated half of the wallet: requests a top-up, subject to
 * the configurable wallet.* limits below, then hands off to the SAME
 * gateway order/webhook path booking payments already use (payments.
 * purpose = 'wallet_topup' distinguishes the two — see PaymentController::
 * handlePaymentCaptured()). No parallel payment record type, no second
 * gateway integration.
 *
 * Limits are enforced HERE, at request time, not inside WalletService::
 * credit() — that stays a generic primitive also used for automatic
 * earnings (CommissionService), which must never be rejected/lost just
 * because a balance cap exists. Only voluntary top-ups are capped.
 */
class WalletTopUpService
{
    public const UNAVAILABLE = 'Top-up is currently unavailable.';

    public function __construct(private PaymentGateway $gateway, private WalletService $walletService)
    {
    }

    /**
     * @param  array  $scope  e.g. ['franchise_id' => .., 'zone_id' => ..] — same shape every other Setting::get() scope hint uses.
     * @throws \RuntimeException if any configured limit would be violated
     */
    public function requestTopUp(User $user, float $amount, array $scope = []): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Top-up amount must be positive.');
        }

        // Wallet top-up is, by definition, a gateway payment -- there's no
        // other way to add funds -- so it's gated by the SAME payment.
        // online_enabled Setting the New Booking modal's dropdown and
        // PaymentController::createOrder() now also check, not a second,
        // invented toggle.
        if (Setting::get('payment.online_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        // REF 1CF-PROMPT-20260925-EARN3 (Rule of Law) — top-up has its own
        // switch, and every limit must be configured: an unset switch or an
        // unset limit means top-up is OFF, never a hidden in-code default.
        if (! EarningsSettings::on('wallet.topup_enabled', $scope)) {
            throw new \RuntimeException(self::UNAVAILABLE);
        }

        // EARN3 D5 — a frozen wallet takes no new top-ups. (A top-up already
        // captured by the gateway is money owed and still credits — see
        // WalletFreezePolicy.)
        $this->walletService->assertNotFrozen($user);

        $min = EarningsSettings::number('wallet.customer_min_topup', $scope);
        $max = EarningsSettings::number('wallet.customer_max_topup', $scope);
        $maxBalance = EarningsSettings::number('wallet.customer_max_balance', $scope);
        $dailyLimit = EarningsSettings::number('wallet.customer_daily_topup_limit', $scope);
        $monthlyLimit = EarningsSettings::number('wallet.customer_monthly_topup_limit', $scope);

        if (in_array(null, [$min, $max, $maxBalance, $dailyLimit, $monthlyLimit], true)) {
            throw new \RuntimeException(self::UNAVAILABLE);
        }

        if ($amount < $min) {
            throw new \RuntimeException("Minimum top-up amount is {$min}.");
        }
        if ($amount > $max) {
            throw new \RuntimeException("Maximum top-up amount is {$max}.");
        }

        $currentBalance = $this->walletService->balance($user);
        if ($currentBalance + $amount > $maxBalance) {
            throw new \RuntimeException("This top-up would exceed the maximum wallet balance of {$maxBalance}.");
        }

        // Pending + captured both count toward the limit -- an abandoned
        // pending request shouldn't let someone bypass the cap by opening
        // several top-ups at once; only 'failed' ones are excluded.
        $toppedUpToday = $this->topUpSumSince($user, now()->startOfDay());
        if ($toppedUpToday + $amount > $dailyLimit) {
            throw new \RuntimeException("This top-up would exceed today's limit of {$dailyLimit}.");
        }

        $toppedUpThisMonth = $this->topUpSumSince($user, now()->startOfMonth());
        if ($toppedUpThisMonth + $amount > $monthlyLimit) {
            throw new \RuntimeException("This top-up would exceed this month's limit of {$monthlyLimit}.");
        }

        $order = $this->gateway->createRawOrder($amount, 'topup-'.Str::random(8), ['user_id' => $user->id, 'purpose' => 'wallet_topup']);

        $payment = Payment::create([
            'user_id' => $user->id,
            'purpose' => 'wallet_topup',
            'amount' => $amount,
            'gateway' => $this->gateway->identifier(),
            'gateway_order_id' => $order['razorpay_order_id'],
            'status' => 'pending',
        ]);

        return [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $order['razorpay_order_id'],
            'razorpay_key_id' => $order['key_id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
        ];
    }

    private function topUpSumSince(User $user, \DateTimeInterface $since): float
    {
        return (float) Payment::where('user_id', $user->id)
            ->where('purpose', 'wallet_topup')
            ->whereIn('status', ['pending', 'captured'])
            ->where('created_at', '>=', $since)
            ->sum('amount');
    }

    /** Called from PaymentController::handlePaymentCaptured() once a topup payment's webhook confirms. */
    public function creditWalletForCapturedTopUp(Payment $payment): void
    {
        $this->walletService->credit(
            $payment->user,
            (float) $payment->amount,
            reason: 'Wallet top-up',
            ref: "topup:{$payment->id}"
        );
    }
}
