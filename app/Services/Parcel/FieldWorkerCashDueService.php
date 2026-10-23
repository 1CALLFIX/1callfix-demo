<?php

namespace App\Services\Parcel;

use App\Models\Commission;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCashDue;
use App\Models\ParcelOrder;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A rider's cash dues: what they owe after collecting cash for a parcel. The FieldWorker counterpart of
 * PayoutService's provider cash-commission sweep, with the same rules:
 *  - recovered from the rider's wallet, oldest due first, only as far as the balance covers (the wallet's
 *    no-negative guard is never loosened);
 *  - the franchise owner is paid their share in proportion to what has actually been recovered so far
 *    ("recompute the cumulative target, pay the delta"), never before;
 *  - run when online earnings land (CommissionService::applyForParcelOrder), when the rider presses
 *    "Settle from wallet", and before any field-worker payout (PayoutService::request()).
 */
class FieldWorkerCashDueService
{
    public function __construct(private WalletService $walletService)
    {
    }

    /** Idempotent: one due per parcel order. A zero split (0% platform, no franchise share) records nothing. */
    public function recordForParcel(ParcelOrder $order, Commission $commission): ?FieldWorkerCashDue
    {
        $owed = round((float) $commission->platform_commission + (float) $commission->franchise_commission, 2);
        if (! $order->assigned_worker_id || $owed <= 0) {
            return null;
        }

        return FieldWorkerCashDue::firstOrCreate(
            ['parcel_order_id' => $order->id],
            [
                'field_worker_id' => $order->assigned_worker_id,
                'commission_id' => $commission->id,
                'platform_portion' => $commission->platform_commission,
                'franchise_portion' => $commission->franchise_commission,
                'amount_owed' => $owed,
                'amount_settled' => 0,
                'status' => 'outstanding',
            ]
        );
    }

    public function outstanding(FieldWorker $worker): float
    {
        return round((float) FieldWorkerCashDue::outstanding()
            ->where('field_worker_id', $worker->id)
            ->sum(DB::raw('amount_owed - amount_settled')), 2);
    }

    /** True when the admin's dues limit is set and this rider has reached it (no more cash orders for them). */
    public function overCashLimit(FieldWorker $worker, array $scope): bool
    {
        $limit = ParcelSettings::get('parcel.rider_cash_due_limit', $scope);

        return $limit !== null && $this->outstanding($worker) >= (float) $limit;
    }

    /** Recover outstanding dues from the rider's wallet. Returns the amount recovered by this call. */
    public function settle(FieldWorker $worker): float
    {
        $worker->loadMissing('user');
        if (! $worker->user) {
            return 0.0;
        }

        return DB::transaction(function () use ($worker) {
            $recovered = 0.0;
            $dues = FieldWorkerCashDue::outstanding()
                ->where('field_worker_id', $worker->id)
                ->with('parcelOrder.franchise.owner')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($dues as $due) {
                $available = $this->walletService->balance($worker->user);
                if ($available <= 0) {
                    break;
                }

                $take = round(min($available, $due->outstandingAmount()), 2);
                if ($take <= 0) {
                    continue;
                }

                $code = $due->parcelOrder?->code;
                $this->walletService->debit(
                    $worker->user,
                    $take,
                    reason: 'Cash dues settlement'.($code ? " — parcel {$code}" : ''),
                    ref: 'rider-cash-due:'.$due->id.':settle:'.Str::uuid()
                );
                $recovered += $take;

                $due->amount_settled = round((float) $due->amount_settled + $take, 2);
                if ($due->amount_settled >= (float) $due->amount_owed) {
                    $due->status = 'settled';
                    $due->settled_at = now();
                }

                if ((float) $due->franchise_portion > 0 && (float) $due->amount_owed > 0) {
                    $target = round($due->amount_settled * ((float) $due->franchise_portion / (float) $due->amount_owed), 2);
                    $payNow = round(max($target - (float) $due->franchise_settled, 0), 2);
                    $owner = $due->parcelOrder?->franchise?->owner;

                    if ($payNow > 0 && $owner) {
                        $this->walletService->credit(
                            $owner,
                            $payNow,
                            reason: 'Franchise revenue share (cash)'.($code ? " for parcel {$code}" : ''),
                            ref: 'rider-cash-due:'.$due->id.':franchise-earning:'.Str::uuid()
                        );
                        $due->franchise_settled = round((float) $due->franchise_settled + $payNow, 2);
                    }
                }

                $due->save();
            }

            return round($recovered, 2);
        });
    }
}
