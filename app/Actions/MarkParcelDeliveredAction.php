<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\ParcelOrderStop;
use App\Models\Payment;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.4 (Parcel) — the ParcelOrder counterpart to CompleteBookingAction.
 * Same structure: lock, validate, OTP-check, mutate+history inside one
 * transaction; commission/loyalty/notification as separate, idempotent
 * steps outside it, same reasoning CompleteBookingAction's own comments
 * give (don't hold the row lock through wallet-crediting or an external
 * call). Loyalty/referral are deliberately NOT wired here — Parcel has no
 * evidence-backed loyalty-points-per-parcel or referral-qualifying-event
 * decision.
 *
 * Stage 2 (owner decisions 2026-10-31): one call hands over ONE drop point with that drop's code — drop 1 (the
 * order's own recipient, $stopId null) or an extra stop of a multi-drop order, in any order. Once every drop is
 * done the order is finished, unless it is a return trip: then the rider brings something back to the sender and
 * MarkParcelReturnedAction finishes it with the sender's return code. Status stays en_route_dropoff meanwhile.
 */
class MarkParcelDeliveredAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    public function execute(int $parcelOrderId, FieldWorker $worker, string $enteredOtp, ?int $stopId = null, ?\App\Support\AdminOverride $override = null): ParcelOrder
    {
        $finished = false;
        $order = DB::transaction(function () use ($parcelOrderId, $worker, $enteredOtp, $stopId, $override, &$finished) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }

            if (! in_array($order->status, ['picked_up', 'en_route_dropoff'], true)) {
                throw new \RuntimeException("Parcel order [{$parcelOrderId}] cannot be marked delivered from status '{$order->status}'.");
            }

            if ($order->drops_completed_at) {
                throw new \RuntimeException('Every drop is done. Take the return back to the sender.');
            }

            // Owner 2026-11-12: admin emergency override, single-drop parcels only (a multi-drop or return trip has
            // a code per stop; the rider finishes those, or the admin cancels).
            if ($override && ($stopId !== null || $order->stops()->exists() || $order->return_trip)) {
                throw new \RuntimeException('An emergency delivery can only be marked on a parcel with one drop and no return.');
            }

            if ($stopId === null) {
                $this->deliverFirstDrop($order, $worker, $enteredOtp, $override !== null);
            } else {
                $this->deliverStop($order, $worker, $enteredOtp, $stopId);
            }

            $order->load('stops');
            if ($order->pendingDropKeys() !== []) {
                // More drops to go: the parcel is out for delivery.
                if ($order->status !== 'en_route_dropoff') {
                    $order->status = 'en_route_dropoff';
                    $order->save();
                }
                event(new ParcelOrderStatusUpdated($order));

                return $order->fresh();
            }

            if ($order->return_trip) {
                $order->drops_completed_at = now();
                $order->status = 'en_route_dropoff';
                $order->save();
                $order->statusHistory()->create([
                    'status' => 'returning', 'changed_by' => $worker->user_id,
                    'note' => 'Every drop done; bringing the return back to the sender', 'changed_at' => now(),
                ]);
                event(new ParcelOrderStatusUpdated($order));

                return $order->fresh();
            }

            self::finish($order, $worker, $override?->note('Marked delivered') ?? 'Delivered with verified OTP', $override?->by->id);
            $override?->log(\App\Models\ParcelOrder::class, $order->id, 'parcel marked delivered');
            $finished = true;

            return $order->fresh();
        });

        if ($finished) {
            self::afterFinish($order, $this->commissionService);
        } elseif ($order->drops_completed_at && $order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('returning', $order, $channels));
        }

        return $order;
    }

    private function deliverFirstDrop(ParcelOrder $order, FieldWorker $worker, string $enteredOtp, bool $skipOtp = false): void
    {
        if ($order->drop_delivered_at) {
            throw new \RuntimeException('This drop is already done.');
        }
        if (! $skipOtp && (empty($order->delivery_otp) || ! hash_equals((string) $order->delivery_otp, $enteredOtp))) {
            throw new \RuntimeException('Incorrect delivery OTP.');
        }

        // Recipient pays cash: collected at the door (the rider's screen says so before the code is entered),
        // recorded exactly like a sender's cash at pickup. (Never on a multi-drop order: cash is paid at pickup.)
        if ($order->collectsCashAtDelivery() && $order->payment_status !== 'paid') {
            Payment::create([
                'parcel_order_id' => $order->id,
                'purpose' => 'parcel_order',
                'amount' => $order->price_quoted,
                'gateway' => 'cash',
                'status' => 'captured',
                'captured_at' => now(),
            ]);
            $order->payment_status = 'paid';
            $order->cash_collected_at = now();
        }

        $order->drop_delivered_at = now();
        // Cash on delivery: the rider has collected the item price for the sender (the screen asks first).
        if ($order->isCod() && ! $order->cod_collected_at) {
            $order->cod_collected_at = now();
        }
        $order->save();

        if ($order->stops()->exists() || $order->return_trip) {
            $order->statusHistory()->create([
                'status' => 'drop_delivered', 'changed_by' => $worker->user_id,
                'note' => 'Drop 1 handed over with verified OTP', 'changed_at' => now(),
            ]);
        }
    }

    private function deliverStop(ParcelOrder $order, FieldWorker $worker, string $enteredOtp, int $stopId): void
    {
        $stop = ParcelOrderStop::whereKey($stopId)->where('parcel_order_id', $order->id)->lockForUpdate()->first();
        if (! $stop) {
            throw new \RuntimeException('That drop point is not on this parcel.');
        }
        if ($stop->delivered_at) {
            throw new \RuntimeException('This drop is already done.');
        }
        if (empty($stop->delivery_otp) || ! hash_equals((string) $stop->delivery_otp, $enteredOtp)) {
            throw new \RuntimeException('Incorrect delivery OTP.');
        }

        $stop->delivered_at = now();
        $stop->save();

        $order->statusHistory()->create([
            'status' => 'drop_delivered', 'changed_by' => $worker->user_id,
            'note' => "Drop {$stop->sequence} handed over with verified OTP", 'changed_at' => now(),
        ]);
    }

    /** Inside the caller's transaction with $order locked: the order is done. */
    public static function finish(ParcelOrder $order, FieldWorker $worker, string $note, ?int $changedBy = null): void
    {
        $order->status = 'delivered';
        $order->price_final = $order->price_quoted;
        $order->delivered_at = now();
        $order->save();

        $order->statusHistory()->create([
            'status' => 'delivered',
            'changed_by' => $changedBy ?? $worker->user_id,
            'note' => $note,
            'changed_at' => now(),
        ]);

        $worker->increment('jobs_completed');

        event(new ParcelOrderStatusUpdated($order));
    }

    /**
     * After the transaction commits: the commission split (same placement as CompleteBookingAction's own
     * applyForBooking() call, deliberately outside the row lock) and the customer's "delivered" message.
     */
    public static function afterFinish(ParcelOrder $order, CommissionService $commissionService): void
    {
        $commissionService->applyForParcelOrder($order);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('delivered', $order, $channels));
        }
    }
}
