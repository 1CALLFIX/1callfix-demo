<?php

namespace App\Services\Parcel;

use App\Contracts\PaymentGateway;
use App\Models\ParcelOrder;
use App\Models\ParcelPack;
use App\Models\ParcelPackPurchase;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\WalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parcel Stage 2 — prepaid parcel packs for businesses (owner decision 2026-10-31).
 *  - Bought ONLINE only: Razorpay (the capture webhook activates it, never the browser) or the wallet.
 *  - Each parcel uses one credit. The credit covers the base fare plus the distance up to the pack's km; distance
 *    beyond it, weight, size, extra drops, the return trip and protection are paid on top, online or from the wallet.
 *  - THUMB RULE (online payment only for all benefits): a parcel that uses a pack credit can never be paid in cash.
 *  - A cancelled parcel gives its credit back (ParcelOrderObserver); an expired pack's unused credits lapse.
 */
class ParcelPackService
{
    public function __construct(private PaymentGateway $gateway, private WalletService $wallet)
    {
    }

    /** @return Collection<int, ParcelPack> active packs on sale for a customer in this franchise (null = HQ-wide only). */
    public function onSale(?int $franchiseId): Collection
    {
        return ParcelPack::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('franchise_id')->when($franchiseId, fn ($q) => $q->orWhere('franchise_id', $franchiseId)))
            ->orderBy('price')->get();
    }

    /** The customer's best usable pack in this franchise: the one expiring first. */
    public function usablePack(User $user, ?int $franchiseId): ?ParcelPackPurchase
    {
        return ParcelPackPurchase::usableIn($user->id, $franchiseId)->orderBy('expires_at')->first();
    }

    // ============================== buying ==============================

    /**
     * @return array{purchase_id: int, requires_payment: bool, checkout?: array}
     */
    public function buy(User $user, ParcelPack $pack, string $method): array
    {
        if (! $pack->is_active) {
            throw new \RuntimeException('This pack is not on sale.');
        }
        if (! in_array($method, ['online', 'wallet'], true)) {
            throw new \RuntimeException('Parcel packs are paid online or from your wallet.');
        }

        $purchase = ParcelPackPurchase::create([
            'parcel_pack_id' => $pack->id, 'user_id' => $user->id, 'franchise_id' => $pack->franchise_id,
            'name' => $pack->name, 'parcels_total' => $pack->parcel_count, 'max_km' => $pack->max_km,
            'price_paid' => $pack->price, 'validity_days' => $pack->validity_days,
            'status' => 'pending_payment', 'payment_method' => $method,
        ]);

        if ($method === 'wallet') {
            if (Setting::get('payment.wallet_enabled') !== '1') {
                $purchase->update(['status' => 'cancelled']);
                throw new \RuntimeException('Wallet payments are not enabled.');
            }
            DB::transaction(function () use ($user, $purchase) {
                $this->wallet->debit($user, (float) $purchase->price_paid, reason: "Parcel pack: {$purchase->name}", ref: "parcel_pack_purchase:{$purchase->id}:wallet-payment");
                $payment = Payment::create([
                    'parcel_pack_purchase_id' => $purchase->id, 'user_id' => $user->id, 'purpose' => 'parcel_pack',
                    'amount' => $purchase->price_paid, 'gateway' => 'wallet', 'status' => 'captured', 'captured_at' => now(),
                ]);
                $this->activate($purchase, $payment);
            });

            return ['purchase_id' => $purchase->id, 'requires_payment' => false];
        }

        if (! $this->gateway->isConfigured()) {
            $purchase->update(['status' => 'cancelled']);
            throw new \RuntimeException('Online payment is not available right now. Try your wallet.');
        }
        $order = $this->gateway->createRawOrder((float) $purchase->price_paid, 'parcelpack-'.$purchase->id, ['parcel_pack_purchase_id' => $purchase->id, 'purpose' => 'parcel_pack']);
        $payment = Payment::create([
            'parcel_pack_purchase_id' => $purchase->id, 'user_id' => $user->id, 'purpose' => 'parcel_pack',
            'amount' => $purchase->price_paid, 'gateway' => $this->gateway->identifier(),
            'gateway_order_id' => $order['razorpay_order_id'], 'status' => 'pending',
        ]);

        return ['purchase_id' => $purchase->id, 'requires_payment' => true, 'checkout' => [
            'payment_id' => $payment->id, 'razorpay_order_id' => $order['razorpay_order_id'], 'razorpay_key_id' => $order['key_id'],
            'amount' => $order['amount'], 'currency' => $order['currency'],
        ]];
    }

    /** Razorpay capture webhook (after its amount check and pending→captured lock): the pack starts now. */
    public function markPaid(Payment $payment): void
    {
        $purchase = ParcelPackPurchase::whereKey($payment->parcel_pack_purchase_id)->lockForUpdate()->first();
        if (! $purchase) {
            return;
        }
        if ($purchase->status !== 'pending_payment') {
            // A second capture for a pack already active: give that money straight back.
            $this->gateway->refund($payment->gateway_payment_id, (float) $payment->amount, "Parcel pack {$purchase->id}: duplicate payment");
            $payment->forceFill(['status' => 'refunded', 'refunded_amount' => $payment->amount])->save();

            return;
        }
        $this->activate($purchase, $payment);
    }

    private function activate(ParcelPackPurchase $purchase, Payment $payment): void
    {
        $purchase->update(['status' => 'active', 'paid_at' => now(), 'expires_at' => now()->addDays($purchase->validity_days)]);
        ActivityLogger::logModel($purchase->user, $purchase, "Parcel pack bought (#{$purchase->id})", ['pack' => $purchase->name, 'amount' => $purchase->price_paid, 'gateway' => $payment->gateway]);
    }

    // ============================== using ==============================

    /**
     * What one credit pays of this quote: the base fare plus the distance up to the pack's km, never more than the
     * delivery price (protection is never covered).
     */
    public static function coverFor(ParcelPackPurchase $purchase, array $quote, array $scope): float
    {
        $base = (float) ($quote['lines'][0]['amount'] ?? 0);
        $coveredKm = max(0.0, min((float) ($quote['distance_km'] ?? 0), (float) $purchase->max_km) - (float) ParcelSettings::get('parcel.base_km', $scope));
        $cover = $base + $coveredKm * (float) ParcelSettings::get('parcel.per_km_rate', $scope);
        $delivery = (float) $quote['total'] - (float) ($quote['protection_fee'] ?? 0);

        return round(max(0.0, min($cover, $delivery)), 2);
    }

    /**
     * Inside the order's transaction: takes one credit from the customer's own usable pack (row-locked, so two
     * parcels can never spend the same last credit). @return ParcelPackPurchase the locked purchase
     */
    public function takeCredit(int $purchaseId, int $userId, ?int $franchiseId): ParcelPackPurchase
    {
        $purchase = ParcelPackPurchase::usableIn($userId, $franchiseId)->whereKey($purchaseId)->lockForUpdate()->first();
        if (! $purchase) {
            throw new \RuntimeException('That parcel pack has no credits left here (used up, expired or for another city).');
        }
        $purchase->increment('parcels_used');

        return $purchase;
    }

    /** A cancelled parcel gives its pack credit back, once. */
    public function returnCredit(ParcelOrder $order): void
    {
        if (! $order->parcel_pack_purchase_id || $order->pack_credit_returned_at) {
            return;
        }
        DB::transaction(function () use ($order) {
            $claimed = ParcelOrder::whereKey($order->id)->whereNull('pack_credit_returned_at')->update(['pack_credit_returned_at' => now()]) === 1;
            if ($claimed) {
                ParcelPackPurchase::whereKey($order->parcel_pack_purchase_id)->where('parcels_used', '>', 0)->decrement('parcels_used');
            }
        });
    }
}
