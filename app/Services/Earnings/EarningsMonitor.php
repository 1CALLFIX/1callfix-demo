<?php

namespace App\Services\Earnings;

use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BundleRefundAuditor;
use App\Services\Loyalty\LoyaltyBalanceAuditor;
use App\Support\EarningsSettings;
use App\Support\WalletFreezePolicy;
use App\Support\WalletSourceLabel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Rule of Law #8: the admin can SEE what
 * the earnings rails are doing. Read-only; every method is a query.
 * Thresholds that are unset switch their flag list OFF (empty), never to a
 * hidden default.
 */
class EarningsMonitor
{
    public function __construct(
        private LoyaltyBalanceAuditor $loyaltyAuditor,
        private BundleRefundAuditor $bundleAuditor,
    ) {
    }

    /**
     * Wallet movement totals per source label for a date range, optionally
     * one franchise (the wallet holder's users.franchise_id).
     *
     * @return array<string, array{label: string, credit: float, debit: float, count: int}>
     */
    public function totalsByLabel(CarbonInterface $from, CarbonInterface $to, ?int $franchiseId = null): array
    {
        $totals = [];

        $this->transactions($from, $to, $franchiseId)
            ->select(['wallet_transactions.id', 'wallet_transactions.ref', 'wallet_transactions.amount', 'wallet_transactions.is_credit'])
            ->orderBy('wallet_transactions.id')
            ->chunk(1000, function ($rows) use (&$totals) {
                foreach ($rows as $row) {
                    $key = WalletSourceLabel::keyFor($row->ref);
                    $totals[$key] ??= ['label' => WalletSourceLabel::labelFor($row->ref), 'credit' => 0.0, 'debit' => 0.0, 'count' => 0];
                    $totals[$key][$row->is_credit ? 'credit' : 'debit'] += (float) $row->amount;
                    $totals[$key]['count']++;
                }
            });

        foreach ($totals as &$t) {
            $t['credit'] = round($t['credit'], 2);
            $t['debit'] = round($t['debit'], 2);
        }

        return $totals;
    }

    /** @return Collection<int, Wallet> */
    public function frozenWallets(): Collection
    {
        return Wallet::query()->with(['user:id,name,phone,role,franchise_id', 'frozenBy:id,name'])
            ->whereNotNull('frozen_at')->orderByDesc('frozen_at')->get();
    }

    /** @return Collection<int, WalletTransaction> */
    public function walletAdjustments(CarbonInterface $from, CarbonInterface $to, ?int $franchiseId = null): Collection
    {
        return $this->transactions($from, $to, $franchiseId)
            ->where('wallet_transactions.ref', 'like', 'admin-adjust:%')
            ->with(['wallet.user:id,name,phone', 'actor:id,name'])
            ->select('wallet_transactions.*')
            ->latest('wallet_transactions.id')->limit(200)->get();
    }

    /** @return Collection<int, LoyaltyPoint> */
    public function pointsAdjustments(CarbonInterface $from, CarbonInterface $to, ?int $franchiseId = null): Collection
    {
        return LoyaltyPoint::query()
            ->with(['user:id,name,phone', 'actor:id,name'])
            ->where('ref', 'like', 'admin-adjust:%')
            ->whereBetween('created_at', [$from, $to])
            ->when($franchiseId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('franchise_id', $franchiseId)))
            ->latest('id')->limit(200)->get();
    }

    /** Refund credits above earnings.flag_refund_above. Unset threshold → flag off → empty. */
    public function refundsAboveThreshold(CarbonInterface $from, CarbonInterface $to, ?int $franchiseId = null): Collection
    {
        $threshold = EarningsSettings::number('earnings.flag_refund_above');
        if ($threshold === null) {
            return collect();
        }

        return WalletSourceLabel::scopeQuery($this->transactions($from, $to, $franchiseId), 'refund', 'wallet_transactions.ref')
            ->where('wallet_transactions.is_credit', true)
            ->where('wallet_transactions.amount', '>', $threshold)
            ->with('wallet.user:id,name,phone')
            ->select('wallet_transactions.*')
            ->latest('wallet_transactions.id')->limit(200)->get();
    }

    /**
     * Referrers with more rewarded referrals than earnings.flag_referrals_above.
     * Unset threshold → flag off → empty.
     *
     * @return Collection<int, object{referrer_id: int, rewarded: int}>
     */
    public function referrersAboveThreshold(): Collection
    {
        $threshold = EarningsSettings::integer('earnings.flag_referrals_above');
        if ($threshold === null) {
            return collect();
        }

        return Referral::query()
            ->selectRaw('referrer_id, COUNT(*) as rewarded')
            ->where('status', 'rewarded')
            ->groupBy('referrer_id')
            ->havingRaw('COUNT(*) > ?', [$threshold])
            ->with('referrer:id,name,phone')
            ->get();
    }

    /** Money owed to the holder that landed while the wallet was frozen (D5: allowed, but flagged). */
    public function creditsToFrozenWallets(): Collection
    {
        $wallets = Wallet::query()->whereNotNull('frozen_at')->get(['id', 'frozen_at']);
        $out = collect();

        foreach ($wallets as $wallet) {
            $rows = WalletTransaction::query()
                ->with('wallet.user:id,name,phone')
                ->where('wallet_id', $wallet->id)
                ->where('is_credit', true)
                ->where('created_at', '>=', $wallet->frozen_at)
                ->latest('id')->get()
                ->filter(fn ($t) => in_array(WalletSourceLabel::keyFor($t->ref), WalletFreezePolicy::MONITORED_CREDITS, true));

            $out = $out->concat($rows);
        }

        return $out->values();
    }

    public function loyaltyAudit(): array
    {
        return $this->loyaltyAuditor->findings();
    }

    public function bundleRefundAudit(): array
    {
        return $this->bundleAuditor->findings();
    }

    private function transactions(CarbonInterface $from, CarbonInterface $to, ?int $franchiseId): Builder
    {
        return WalletTransaction::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->whereBetween('wallet_transactions.created_at', [$from, $to])
            ->when($franchiseId, fn ($q) => $q->where('users.franchise_id', $franchiseId));
    }
}
