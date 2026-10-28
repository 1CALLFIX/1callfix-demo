<?php

namespace App\Services\Marketplace;

use App\Models\Commission;
use App\Models\FieldWorker;
use App\Models\Franchise;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Services\ProviderCommercialRateResolver;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E-commerce Phase 0 (2026-10-25) — splits and pays a completed marketplace order. Replaces the old
 * CommissionService::applyForMarketplaceOrder() split, which credited the seller the full net amount at
 * delivery even when the rider (or the seller) was holding the cash, and paid the rider nothing.
 *
 * Two splits, same rate rules as every other module (ProviderCommercialRateResolver + franchise revenue share):
 *  - goods (subtotal − discount + tax): seller / platform / franchise;
 *  - delivery fee: rider / platform / franchise (a FieldWorker has no negotiated agreement, so franchise → global).
 *
 * Minimum rider payout (marketplace.rider_min_payout, unset = none): when the rider's share of the fee is below
 * it — a free-delivery order has no fee at all — the gap is added to the rider's share. Who pays the gap is
 * marketplace.rider_min_payout_funded_by: 'platform' (default) takes it out of the platform's commission,
 * 'seller' out of the seller's share (never more than that share). Pickup orders have no rider, so no payout.
 *
 * When each share is paid:
 *  - rider share: at delivery (online), or kept out of the cash the rider collected (cash_delivery);
 *  - seller share: after the return window (marketplace.return_window_days, default 3 — owner decision
 *    2026-10-24), never before. For cash_delivery also only once the rider has paid the cash back in full;
 *  - franchise share: delivery part at delivery, goods part with the seller (online). On cash orders the
 *    franchise is paid in proportion to the cash actually recovered (same rule as FieldWorkerCashDueService).
 *
 * Cash: whoever holds it owes back everything that is not theirs. Recovered from that person's wallet, oldest
 * first, only as far as the balance covers — at payout time and when the seller's release runs.
 */
class MarketplaceSettlementService
{
    public function __construct(
        private ProviderCommercialRateResolver $rateResolver,
        private WalletService $walletService,
    ) {
    }

    /** Idempotent: one settlement (and one Commission row) per order. */
    public function recordOnCompletion(MarketplaceOrder $order): MarketplaceSettlement
    {
        $existing = MarketplaceSettlement::where('marketplace_order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $order->loadMissing(['franchise.owner', 'store.provider.user', 'assignedWorker.user']);
        $franchise = $order->franchise;
        $seller = $order->store?->provider;
        $rider = $order->order_type === 'delivery' ? $order->assignedWorker : null;

        $total = (float) ($order->price_final ?? $order->total_amount);
        $deliveryAmount = $rider ? round((float) $order->delivery_fee, 2) : 0.0;
        // Coupons are HQ-funded: the seller and franchise earn on the full goods value; the platform carries the
        // discount out of its own share (which can go below zero on a big coupon).
        $couponDiscount = round((float) $order->coupon_discount_amount, 2);
        $goodsAmount = round($total - $deliveryAmount + $couponDiscount, 2);

        // E-commerce Phase 4: the goods fee comes from Admin → Shop Commission (shop / category / franchise / city).
        $goodsRate = $order->store ? app(MarketplaceCommissionResolver::class)->rateForOrder($order) : null;
        [$goodsPlatform, $goodsFranchise, $sellerShare] = $this->split($franchise, $seller, $goodsAmount, $goodsRate);
        $goodsPlatform = round($goodsPlatform - $couponDiscount, 2);
        [$deliveryPlatform, $deliveryFranchise, $riderShare] = $this->split($franchise, null, $deliveryAmount);

        $scope = array_filter([
            'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
            'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id,
        ]);

        [$riderTopup, $topupFundedBy] = [0.0, null];
        $minPayout = $rider ? (float) Setting::get('marketplace.rider_min_payout', '', $scope) : 0.0;
        if ($minPayout > $riderShare) {
            $topupFundedBy = Setting::get('marketplace.rider_min_payout_funded_by', 'platform', $scope) === 'seller'
                ? 'seller' : 'platform';
            $riderTopup = round($minPayout - $riderShare, 2);
            if ($topupFundedBy === 'seller') {
                $riderTopup = round(min($riderTopup, $sellerShare), 2);
                $sellerShare = round($sellerShare - $riderTopup, 2);
            }
            $riderShare = round($riderShare + $riderTopup, 2);
        }
        $platformTopup = $topupFundedBy === 'platform' ? $riderTopup : 0.0;

        // E-commerce Phase 5: GST TCS and income-tax TDS (194-O) the platform withholds from the seller on the goods'
        // taxable value (before GST), for the platform to deposit. Rates are Shop Settings values; unset = nothing.
        $taxable = round((float) $order->subtotal, 2);
        $tcs = round($taxable * (float) (\App\Support\MarketplaceSettings::get('marketplace.tcs_gst_percent') ?? 0) / 100, 2);
        $tds = round($taxable * (float) (\App\Support\MarketplaceSettings::get('marketplace.tds_194o_percent') ?? 0) / 100, 2);
        $withheld = min($sellerShare, round($tcs + $tds, 2));
        $sellerShare = round($sellerShare - $withheld, 2);

        $mode = match (true) {
            $order->payment_method !== 'cash' => 'online',
            $order->order_type === 'delivery' => 'cash_delivery',
            default => 'cash_pickup',
        };

        // What the cash holder owes back: the rider keeps only their own share; the seller (pickup) keeps theirs.
        [$debtorType, $cashOwed, $cashFranchise] = match ($mode) {
            'cash_delivery' => ['field_worker', max(round($total - $riderShare, 2), 0.0), round($goodsFranchise + $deliveryFranchise, 2)],
            // ...plus any TCS / TDS withheld (Phase 5): the seller holds the cash, so they owe that back too.
            'cash_pickup' => ['provider', round($goodsPlatform + $goodsFranchise + $withheld, 2), $goodsFranchise],
            default => [null, 0.0, 0.0],
        };

        $windowDays = max(0, (int) Setting::get('marketplace.return_window_days', '3', $scope));

        $settlement = DB::transaction(function () use (
            $order, $seller, $rider, $mode, $goodsAmount, $sellerShare, $goodsPlatform, $goodsFranchise,
            $deliveryAmount, $riderShare, $deliveryPlatform, $deliveryFranchise, $debtorType, $cashOwed, $cashFranchise, $windowDays,
            $riderTopup, $topupFundedBy, $platformTopup, $tcs, $tds, $withheld
        ) {
            $locked = MarketplaceSettlement::where('marketplace_order_id', $order->id)->lockForUpdate()->first();
            if ($locked) {
                return $locked;
            }

            $commission = Commission::create([
                'marketplace_order_id' => $order->id,
                'provider_commission' => $sellerShare, // the seller's share; the rider's is on the settlement row
                'franchise_commission' => round($goodsFranchise + $deliveryFranchise, 2),
                // Net of a platform-funded rider top-up (can be negative: the platform paid to get it delivered).
                'platform_commission' => round($goodsPlatform + $deliveryPlatform - $platformTopup, 2),
            ]);

            $settlement = MarketplaceSettlement::create([
                'marketplace_order_id' => $order->id,
                'commission_id' => $commission->id,
                'provider_id' => $seller?->id,
                'field_worker_id' => $rider?->id,
                'payment_mode' => $mode,
                'goods_amount' => $goodsAmount,
                'seller_share' => $sellerShare,
                'goods_platform' => $goodsPlatform,
                'goods_franchise' => $goodsFranchise,
                // Withheld for the government; capped at the seller's share (TCS first).
                'tcs_gst_amount' => min($tcs, $withheld),
                'tds_income_tax_amount' => round($withheld - min($tcs, $withheld), 2),
                'delivery_amount' => $deliveryAmount,
                'rider_share' => $riderShare,
                'delivery_platform' => $deliveryPlatform,
                'delivery_franchise' => $deliveryFranchise,
                'rider_topup' => $riderTopup,
                'rider_topup_funded_by' => $riderTopup > 0 ? $topupFundedBy : null,
                // A cash-pickup seller already holds their share, so there is nothing to hold back.
                'release_at' => ($order->completed_at ?? now())->copy()->addDays($windowDays),
                'status' => $mode === 'cash_pickup' ? 'released' : 'held',
                'released_at' => $mode === 'cash_pickup' ? now() : null,
                'cash_debtor_type' => $cashOwed > 0 ? $debtorType : null,
                'cash_owed' => $cashOwed,
                'cash_franchise_portion' => $cashOwed > 0 ? $cashFranchise : 0,
                'cash_status' => $cashOwed > 0 ? 'outstanding' : 'none',
            ]);

            if ($mode === 'online') {
                if ($rider?->user && $riderShare > 0) {
                    $this->walletService->credit(
                        $rider->user,
                        $riderShare,
                        reason: "Delivery earnings for order {$order->code}",
                        ref: "marketplace_order:{$order->id}:worker-earning"
                    );
                }
                if ($order->franchise?->owner && $deliveryFranchise > 0) {
                    $this->walletService->credit(
                        $order->franchise->owner,
                        $deliveryFranchise,
                        reason: "Franchise revenue share (delivery) for order {$order->code}",
                        ref: "marketplace_order:{$order->id}:delivery-franchise-earning"
                    );
                }
            }

            return $settlement;
        });

        // Fresh online earnings can settle this rider's older cash dues straight away.
        if ($rider && $mode === 'online') {
            $this->settleCash('field_worker', $rider->id);
        }

        return $settlement;
    }

    /**
     * Pays the held seller share (and the online franchise goods share) once the return window has passed. For
     * cash_delivery the rider's cash must be fully recovered first. Returns how many settlements were released.
     */
    public function releaseDue(): int
    {
        $released = 0;

        MarketplaceSettlement::where('status', 'held')
            ->where('release_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$released) {
                foreach ($rows as $row) {
                    if ($row->payment_mode === 'cash_delivery' && $row->field_worker_id) {
                        $this->settleCash('field_worker', $row->field_worker_id);
                    }
                    if ($this->release($row->id)) {
                        $released++;
                    }
                }
            });

        return $released;
    }

    private function release(int $settlementId): bool
    {
        return DB::transaction(function () use ($settlementId) {
            $row = MarketplaceSettlement::with(['marketplaceOrder.franchise.owner', 'provider.user'])
                ->lockForUpdate()->find($settlementId);

            if (! $row || $row->status !== 'held' || $row->release_at->isFuture()) {
                return false;
            }
            if ($row->payment_mode === 'cash_delivery' && $row->cash_status === 'outstanding') {
                return false; // the rider has not yet paid the cash back
            }
            // E-commerce Phase 3b: an open return keeps the seller's money held until its refund is settled.
            if (\App\Models\MarketplaceReturn::where('marketplace_order_id', $row->marketplace_order_id)->whereIn('status', \App\Models\MarketplaceReturn::OPEN)->exists()) {
                return false;
            }

            $order = $row->marketplaceOrder;
            if ($row->provider?->user && (float) $row->seller_share > 0) {
                $this->walletService->credit(
                    $row->provider->user,
                    (float) $row->seller_share,
                    reason: "Sales earnings for order {$order->code}",
                    ref: "marketplace_order:{$order->id}:provider-earning"
                );
            }
            // Cash orders pay the franchise as the cash comes in (settleCash), never here.
            if ($row->payment_mode === 'online' && $order->franchise?->owner && (float) $row->goods_franchise > 0) {
                $this->walletService->credit(
                    $order->franchise->owner,
                    (float) $row->goods_franchise,
                    reason: "Franchise revenue share for order {$order->code}",
                    ref: "marketplace_order:{$order->id}:franchise-earning"
                );
            }

            $row->status = 'released';
            $row->released_at = now();
            $row->save();

            return true;
        });
    }

    /** Cash still owed by a rider ('field_worker') or a seller ('provider'). */
    public function outstanding(string $debtorType, int $debtorId): float
    {
        return round((float) MarketplaceSettlement::cashOutstandingFor($debtorType, $debtorId)
            ->sum(DB::raw('cash_owed - cash_settled')), 2);
    }

    /**
     * Recovers cash owed from the debtor's wallet, oldest first, only as far as the balance covers. The
     * franchise is paid its share of what was actually recovered ("recompute the cumulative target, pay the
     * delta"). Returns the amount recovered by this call.
     */
    public function settleCash(string $debtorType, int $debtorId): float
    {
        $user = $this->debtorUser($debtorType, $debtorId);
        if (! $user) {
            return 0.0;
        }

        return DB::transaction(function () use ($debtorType, $debtorId, $user) {
            $recovered = 0.0;
            $rows = MarketplaceSettlement::cashOutstandingFor($debtorType, $debtorId)
                ->with('marketplaceOrder.franchise.owner')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $available = $this->walletService->balance($user);
                if ($available <= 0) {
                    break;
                }

                $take = round(min($available, $row->cashOutstanding()), 2);
                if ($take <= 0) {
                    continue;
                }

                $code = $row->marketplaceOrder?->code;
                $this->walletService->debit(
                    $user,
                    $take,
                    reason: 'Cash collected settlement'.($code ? " — order {$code}" : ''),
                    ref: 'mkt-cash-due:'.$row->id.':settle:'.Str::uuid()
                );
                $recovered += $take;

                $row->cash_settled = round((float) $row->cash_settled + $take, 2);
                if ($row->cash_settled >= (float) $row->cash_owed) {
                    $row->cash_status = 'settled';
                    $row->cash_settled_at = now();
                }

                if ((float) $row->cash_franchise_portion > 0 && (float) $row->cash_owed > 0) {
                    $target = round($row->cash_settled * ((float) $row->cash_franchise_portion / (float) $row->cash_owed), 2);
                    $payNow = round(max($target - (float) $row->cash_franchise_settled, 0), 2);
                    $owner = $row->marketplaceOrder?->franchise?->owner;

                    if ($payNow > 0 && $owner) {
                        $this->walletService->credit(
                            $owner,
                            $payNow,
                            reason: 'Franchise revenue share (cash)'.($code ? " for order {$code}" : ''),
                            ref: 'mkt-cash-due:'.$row->id.':franchise-earning:'.Str::uuid()
                        );
                        $row->cash_franchise_settled = round((float) $row->cash_franchise_settled + $payNow, 2);
                    }
                }

                $row->save();
            }

            return round($recovered, 2);
        });
    }

    /** @return array{0: float, 1: float, 2: float} platform, franchise, earner */
    private function split(?Franchise $franchise, ?Provider $provider, float $amount, ?float $rate = null): array
    {
        if ($amount <= 0 || ! $franchise) {
            return [0.0, 0.0, max(round($amount, 2), 0.0)];
        }

        $platform = round($amount * (($rate ?? $this->rateResolver->resolve($franchise, $provider)) / 100), 2);
        $franchiseShare = $franchise->commission_model === 'revenue_share'
            ? round($amount * (($franchise->commission_value ?? 0) / 100), 2)
            : 0.0;

        return [$platform, $franchiseShare, round($amount - $platform - $franchiseShare, 2)];
    }

    private function debtorUser(string $debtorType, int $debtorId): ?User
    {
        return match ($debtorType) {
            'provider' => Provider::with('user')->find($debtorId)?->user,
            'field_worker' => FieldWorker::with('user')->find($debtorId)?->user,
            default => null,
        };
    }
}
