<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\AdminOpsAlertService;
use App\Services\CancellationService;
use App\Services\DispatchService;
use App\Services\Parcel\ParcelSettings;
use App\Services\Parcel\ParcelWaitingCharge;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parcel pickup safety (owner go-ahead 2026-10-29): the rider refuses the parcel at the pickup point — it holds a
 * banned item, the sender would not let the rider check or photograph it, or something else is not right. The rider
 * never argues: they refuse here and leave, and admins are emailed.
 *
 * Only before pickup, only by the assigned rider, and only from the rider's live location within
 * parcel.arrival_radius_km of the pickup pin (so a job cannot be dropped from across town for the trip pay).
 * The order is cancelled. Money, both amounts Super Admin settings that do nothing while unset:
 *  - parcel.refusal_fee is kept from the sender's online / wallet refund, only for a banned item or a refused
 *    check (ParcelSettings::REFUSAL_REASONS 'fee'); a cash order has nothing to keep it from.
 *  - parcel.refusal_rider_pay is credited to the rider's wallet for the wasted trip.
 */
class RefuseParcelAtPickupAction
{
    public function __construct(
        private DispatchService $dispatch,
        private CancellationService $cancellationService,
        private WalletService $walletService,
    ) {
    }

    public function execute(int $parcelOrderId, FieldWorker $worker, string $reason, ?string $note, ?float $lat, ?float $lng): ParcelOrder
    {
        $meta = ParcelSettings::REFUSAL_REASONS[$reason] ?? null;
        if (! $meta) {
            throw new \RuntimeException('Choose why you are refusing the parcel.');
        }
        $note = mb_substr(trim((string) $note), 0, 300);
        if ($reason === 'suspicious' && mb_strlen($note) < 3) {
            throw new \RuntimeException('Say in a few words what is not right.');
        }
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new \RuntimeException("We couldn't read your location. Allow location access and try again, or call support.");
        }

        $order = DB::transaction(function () use ($parcelOrderId, $worker, $reason, $meta, $note, $lat, $lng) {
            $order = ParcelOrder::with('pickupAddress')->lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }
            if (! in_array($order->status, ['assigned', 'worker_en_route_pickup'], true)) {
                throw new \RuntimeException('You can only refuse a parcel before you pick it up. Already picked up and something is wrong? Stop, do not open it, and call support.');
            }

            $scope = ParcelWaitingCharge::scope($order);
            $radius = (float) ParcelSettings::get('parcel.arrival_radius_km', $scope);
            $away = $this->dispatch->haversineKm($lat, $lng, (float) $order->pickupAddress->lat, (float) $order->pickupAddress->lng);
            if ($away > $radius) {
                throw new \RuntimeException('You are '.round($away, 1).' km from the pickup point. You can refuse the parcel once you are there.');
            }

            $paidOnline = $order->payment_method !== 'cash' && $order->payment_status === 'paid';
            $feeSetting = ParcelSettings::get('parcel.refusal_fee', $scope);
            $fee = ($meta['fee'] && $paidOnline && $feeSetting > 0) ? round(min((float) $feeSetting, (float) $order->price_quoted), 2) : 0.0;

            $order->fill([
                'status' => 'cancelled',
                'cancellation_note' => 'Refused at pickup by the rider: '.$meta['label'],
                'cancellation_fee' => $fee,
                'refused_at' => now(),
                'refusal_reason' => $reason,
                'refusal_note' => $note === '' ? null : $note,
                'refused_lat' => $lat,
                'refused_lng' => $lng,
                'safety_flagged_at' => $order->safety_flagged_at ?? now(),
            ])->save();

            $order->statusHistory()->create([
                'status' => 'cancelled',
                'changed_by' => $worker->user_id,
                'note' => 'Refused at pickup by the rider: '.$meta['label'].($fee > 0 ? " (refusal fee kept: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            $worker->forceFill(['current_lat' => $lat, 'current_lng' => $lng, 'location_updated_at' => now()])->save();

            event(new ParcelOrderStatusUpdated($order));

            return $order;
        });

        if ($order->customer) {
            try {
                $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
                $order->customer->notify(new ParcelOrderStatusNotification('refused', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver parcel refused notification for parcel order [{$order->id}]: ".$e->getMessage());
            }
        }

        // The order is closed whatever happens below: a refund or wallet failure is logged for admins, never undone.
        try {
            $this->cancellationService->refundIfPaidForParcelOrder($order, (float) $order->cancellation_fee);
        } catch (\Throwable $e) {
            Log::error("Refund failed for refused parcel order [{$order->id}]: ".$e->getMessage());
        }

        $this->payRider($order, $worker);

        try {
            app(AdminOpsAlertService::class)->parcelRefused($order->fresh());
        } catch (\Throwable $e) {
            Log::warning("Parcel refused alert failed for parcel order [{$order->id}]: ".$e->getMessage());
        }

        return $order->fresh();
    }

    private function payRider(ParcelOrder $order, FieldWorker $worker): void
    {
        $pay = ParcelSettings::get('parcel.refusal_rider_pay', ParcelWaitingCharge::scope($order));
        $worker->loadMissing('user');
        if (! $pay || $pay <= 0 || ! $worker->user) {
            return;
        }

        try {
            $this->walletService->credit($worker->user, (float) $pay, reason: "Trip pay for refused parcel {$order->code}", ref: "parcel_order:{$order->id}:refusal-pay");
            $order->forceFill(['refusal_rider_pay' => $pay])->save();
        } catch (\Throwable $e) {
            Log::error("Trip pay failed for refused parcel order [{$order->id}]: ".$e->getMessage());
        }
    }
}
