<?php

namespace App\Services\Marketplace;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Notifications\MarketplaceNoRiderNotification;
use App\Notifications\RiderShopOfferNotification;
use App\Services\DispatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-11-09: "no option for restaurant to assign it to a local rider". A restaurant / shop (its own orders) or
 * an admin (marketplace_orders.view in scope) picks one of the nearby 1CallFix riders the automatic search would use
 * (online, fresh location, right rider type, not busy, inside the zone radius). That rider's phone rings and he
 * accepts as usual (AcceptMarketplaceDeliveryOfferAction); nothing is forced on him, the delivery fee and rules are
 * unchanged. Also tells the restaurant and admins when the automatic search found nobody (alertNoRider).
 */
class RiderPicker
{
    public function __construct(private DispatchService $dispatch)
    {
    }

    /** A delivery order with no rider yet, cooking or ready. */
    public function canPick(MarketplaceOrder $order): bool
    {
        return $order->order_type === 'delivery'
            && $order->assigned_worker_id === null
            && in_array($order->status, ['preparing', 'ready'], true);
    }

    /** @return Collection<int, array{provider: FieldWorker, distance_km: float}> nearest first */
    public function candidates(MarketplaceOrder $order): Collection
    {
        return $this->canPick($order) ? $this->dispatch->findMarketplaceDeliveryRiderCandidates($order, 10, manualPick: true) : collect();
    }

    /** Rings one chosen rider. $who is shown in the order history ("Restaurant", "Admin"). */
    public function offer(MarketplaceOrder $order, int $workerId, User $by, string $who): FieldWorker
    {
        $rider = DB::transaction(function () use ($order, $workerId, $by, $who) {
            $order = MarketplaceOrder::lockForUpdate()->findOrFail($order->id);
            if (! $this->canPick($order)) {
                throw new \RuntimeException('This order already has a rider, or is no longer waiting for one.');
            }
            $pick = $this->candidates($order)->first(fn (array $c) => $c['provider']->id === $workerId);
            if (! $pick) {
                throw new \RuntimeException('That rider is no longer free nearby. Pick another one.');
            }
            /** @var FieldWorker $rider */
            $rider = $pick['provider'];

            // Cooking and no rider called yet: picking one is calling him now, so he can accept (isOpenForRider).
            if ($order->status === 'preparing' && $order->rider_called_at === null) {
                $order->rider_called_at = now();
                $order->save();
            }

            DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)->where('dispatchable_id', $order->id)
                ->where('notifiable_type', FieldWorker::class)->where('notifiable_id', $rider->id)
                ->where('status', 'notified')->update(['status' => 'timeout', 'responded_at' => now()]);
            DispatchAttempt::create([
                'dispatchable_type' => MarketplaceOrder::class,
                'dispatchable_id' => $order->id,
                'notifiable_type' => FieldWorker::class,
                'notifiable_id' => $rider->id,
                'status' => 'notified',
                'distance_km' => $pick['distance_km'],
                'notified_at' => now(),
            ]);
            $order->statusHistory()->create([
                'status' => $order->status,
                'changed_by' => $by->id,
                'note' => "{$who} asked rider ".($rider->user?->name ?? "#{$rider->id}").' to deliver',
                'changed_at' => now(),
            ]);

            return $rider;
        });

        optional($rider->user)->notify(new RiderShopOfferNotification($order->fresh()));

        return $rider;
    }

    /** The automatic search ran out: tell the restaurant / shop and the admins watching these orders. */
    public function alertNoRider(MarketplaceOrder $order): void
    {
        $order->loadMissing('store.provider.user', 'franchise');
        $order->statusHistory()->create([
            'status' => $order->status,
            'note' => 'No rider accepted. Restaurant / shop and admins asked to pick a rider.',
            'changed_at' => now(),
        ]);

        try {
            $order->store?->provider?->user?->notify(new MarketplaceNoRiderNotification($order, route('seller.orders')));

            $scope = array_filter([
                'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
                'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
            ]);
            $authz = app(\App\Services\AuthorizationService::class);
            User::where('push_ops_alerts', true)->whereNotNull('fcm_token')->where('status', '!=', 'suspended')
                ->chunkById(200, function ($admins) use ($authz, $scope, $order) {
                    foreach ($admins as $admin) {
                        if ($authz->can($admin, 'marketplace_orders.view', $scope)) {
                            $admin->notify(new MarketplaceNoRiderNotification($order, route('admin.marketplace-orders.index', ['moduleFilter' => $order->isFood() ? 'food' : 'shop'])));
                        }
                    }
                });
        } catch (\Throwable $e) {
            Log::warning('RiderPicker: no-rider alert failed.', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
