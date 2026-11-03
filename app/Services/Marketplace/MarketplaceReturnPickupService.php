<?php

namespace App\Services\Marketplace;

use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\MarketplaceSettlement;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\WalletService;
use App\Support\MarketplaceSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shop go-live (owner 2026-10-31) — a rider collects an accepted return from the customer and takes it back to the
 * shop. Owner decision: the rider's fee is paid by the SELLER out of their held earnings; the customer pays nothing.
 *
 *  open      the return was accepted on a delivery order and marketplace.return_pickup_fee is set (blank = no rider
 *            pickup: the seller or an admin marks it received by hand, as before). Online riders of the order's zone
 *            who carry shop/parcel deliveries see it on Rider → Shop deliveries; the first to accept takes it.
 *  assigned  the rider is going to the customer. The customer reads the pickup code from their order page.
 *  picked    the rider has the item. The seller reads the drop code from Seller → Orders.
 *  dropped   the item is back at the shop: the return is RECEIVED (the refund then goes through the approval model,
 *            MarketplaceReturnService) and the rider is paid the fee. The fee comes out of the seller's held share;
 *            if that share is smaller than the fee, the platform covers the rest (logged, pickup_fee_platform).
 *
 * The seller's earnings are still held at that point: releaseDue() skips an order with an open return.
 * One job at a time: a rider with a parcel, a shop delivery or another pickup is not offered one.
 */
class MarketplaceReturnPickupService
{
    public const FEE_KEY = 'marketplace.return_pickup_fee';

    public function __construct(private WalletService $wallet)
    {
    }

    /** Called when an admin accepts a return (inside its transaction, row already locked). */
    public function openFor(MarketplaceReturn $r): void
    {
        $order = MarketplaceOrder::find($r->marketplace_order_id);
        $fee = MarketplaceSettings::get(self::FEE_KEY);
        if (! $order || $order->order_type !== 'delivery' || $fee === null || ! $order->delivery_address_id) {
            return; // pickup order (the customer brings it back) or no fee set: received by hand.
        }
        $r->pickup_status = MarketplaceReturn::PICKUP_OPEN;
        $r->pickup_fee = round((float) $fee, 2);
    }

    /** The return this rider is collecting or carrying, if any. */
    public function activeFor(FieldWorker $rider): ?MarketplaceReturn
    {
        return MarketplaceReturn::where('pickup_worker_id', $rider->id)
            ->whereIn('pickup_status', MarketplaceReturn::PICKUP_WITH_RIDER)
            ->with(['order.deliveryAddress', 'order.items', 'store', 'customer:id,name,phone'])
            ->latest('id')->first();
    }

    /** Open pickups this rider may take: same zone, a shop/parcel rider, the customer within the zone's radius. */
    public function openPickupsFor(FieldWorker $rider): Collection
    {
        if (! $rider->is_online || $this->isBusy($rider) || ! $this->canCarry($rider)) {
            return collect();
        }

        return MarketplaceReturn::where('pickup_status', MarketplaceReturn::PICKUP_OPEN)
            ->whereHas('order', fn ($q) => $q->where('zone_id', $rider->zone_id))
            ->with(['order.deliveryAddress', 'order.zone', 'store'])
            ->oldest('decided_at')->limit(20)->get()
            ->map(function (MarketplaceReturn $r) use ($rider) {
                $a = $r->order?->deliveryAddress;
                $r->setAttribute('distance_km', ($a && $a->lat !== null && $rider->current_lat !== null)
                    ? round($this->km((float) $rider->current_lat, (float) $rider->current_lng, (float) $a->lat, (float) $a->lng), 1) : null);

                return $r;
            })
            ->filter(fn (MarketplaceReturn $r) => $r->distance_km === null || $r->distance_km <= (float) ($r->order->zone->default_dispatch_radius_km ?? 8))
            ->values();
    }

    public function isBusy(FieldWorker $rider): bool
    {
        return ParcelOrder::where('assigned_worker_id', $rider->id)->whereIn('status', ParcelOrder::ACTIVE_RIDER_STATUSES)->exists()
            || MarketplaceOrder::where('assigned_worker_id', $rider->id)->riderActive()->exists()
            || MarketplaceReturn::where('pickup_worker_id', $rider->id)->whereIn('pickup_status', MarketplaceReturn::PICKUP_WITH_RIDER)->exists();
    }

    private function canCarry(FieldWorker $rider): bool
    {
        return $rider->capabilities()->whereIn('capability_type', ['commerce_delivery_rider', 'parcel_rider'])->exists();
    }

    public function accept(int $returnId, FieldWorker $rider): MarketplaceReturn
    {
        if ($this->isBusy($rider)) {
            throw new \RuntimeException('Finish your current job first.');
        }
        if (! $this->canCarry($rider)) {
            throw new \RuntimeException('Your account does not take shop jobs.');
        }

        return DB::transaction(function () use ($returnId, $rider) {
            $r = MarketplaceReturn::whereKey($returnId)->lockForUpdate()->firstOrFail();
            if ($r->pickup_status !== MarketplaceReturn::PICKUP_OPEN || $r->status !== MarketplaceReturn::ACCEPTED) {
                throw new \RuntimeException('This pickup has already been taken.');
            }
            if ((int) MarketplaceOrder::whereKey($r->marketplace_order_id)->value('zone_id') !== (int) $rider->zone_id) {
                throw new \RuntimeException('This pickup is not in your area.');
            }
            $r->pickup_status = MarketplaceReturn::PICKUP_ASSIGNED;
            $r->pickup_worker_id = $rider->id;
            $r->pickup_otp = $this->code();
            $r->drop_otp = $this->code();
            $r->pickup_assigned_at = now();
            $r->save();
            ActivityLogger::logModel($rider->user, $r, "Shop return #{$r->id}: pickup accepted by rider #{$rider->id}");

            return $r;
        });
    }

    /** At the customer's door: the customer's pickup code. */
    public function confirmPickup(FieldWorker $rider, string $otp): MarketplaceReturn
    {
        return DB::transaction(function () use ($rider, $otp) {
            $r = MarketplaceReturn::where('pickup_worker_id', $rider->id)->where('pickup_status', MarketplaceReturn::PICKUP_ASSIGNED)
                ->lockForUpdate()->first();
            if (! $r) {
                throw new \RuntimeException('You have no return to collect.');
            }
            if (! hash_equals((string) $r->pickup_otp, trim($otp))) {
                throw new \RuntimeException('That code is not right. Ask the customer to read it from their order page.');
            }
            $r->pickup_status = MarketplaceReturn::PICKUP_PICKED;
            $r->picked_up_at = now();
            $r->save();
            ActivityLogger::logModel($rider->user, $r, "Shop return #{$r->id}: item collected from the customer");

            return $r;
        });
    }

    /** At the shop: the seller's drop code. The return becomes RECEIVED and the rider is paid (seller pays). */
    public function confirmDrop(FieldWorker $rider, string $otp): MarketplaceReturn
    {
        $r = DB::transaction(function () use ($rider, $otp) {
            $r = MarketplaceReturn::where('pickup_worker_id', $rider->id)->where('pickup_status', MarketplaceReturn::PICKUP_PICKED)
                ->lockForUpdate()->first();
            if (! $r) {
                throw new \RuntimeException('You are not carrying a return.');
            }
            if (! hash_equals((string) $r->drop_otp, trim($otp))) {
                throw new \RuntimeException('That code is not right. Ask the shop to read it from Seller → Orders.');
            }

            $fee = round((float) $r->pickup_fee, 2);
            $sellerPart = 0.0;
            $row = MarketplaceSettlement::where('marketplace_order_id', $r->marketplace_order_id)->lockForUpdate()->first();
            if ($row && $row->status === 'held') {
                $sellerPart = round(min($fee, max(0.0, (float) $row->seller_share)), 2);
                $row->seller_share = round((float) $row->seller_share - $sellerPart, 2);
                $row->save();
                $row->commission?->update(['provider_commission' => $row->seller_share]);
            }

            $r->pickup_status = MarketplaceReturn::PICKUP_DROPPED;
            $r->dropped_at = now();
            $r->pickup_fee_seller = $sellerPart;
            $r->pickup_fee_platform = round($fee - $sellerPart, 2);
            if ($r->status === MarketplaceReturn::ACCEPTED) {
                $r->status = MarketplaceReturn::RECEIVED;
                $r->received_at = now();
                $r->refund_status = MarketplaceReturn::REFUND_AWAITING_REQUEST;
            }
            $r->save();

            if ($fee > 0 && $rider->user) {
                $this->wallet->credit($rider->user, $fee,
                    reason: "Return pickup earnings for return #{$r->id}",
                    ref: "marketplace_return:{$r->id}:pickup-earning");
            }
            ActivityLogger::logModel($rider->user, $r, "Shop return #{$r->id}: item back at the shop (rider)",
                ['fee' => $fee, 'paid_by_seller' => $sellerPart, 'paid_by_platform' => $r->pickup_fee_platform]);

            return $r;
        });

        return $r;
    }

    /**
     * The item came back another way (marked received by hand): any pickup not yet dropped is withdrawn, no fee.
     * MarketplaceReturnService::markReceived() allows that for the seller only while no rider has taken it.
     */
    public function withdraw(MarketplaceReturn $r): void
    {
        if (in_array($r->pickup_status, [MarketplaceReturn::PICKUP_OPEN, ...MarketplaceReturn::PICKUP_WITH_RIDER], true)) {
            $r->pickup_status = MarketplaceReturn::PICKUP_CANCELLED;
        }
    }

    private function code(): string
    {
        $len = (int) Setting::get('booking.otp_length', 4);

        return (string) random_int((int) (10 ** ($len - 1)), (int) (10 ** $len) - 1);
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
