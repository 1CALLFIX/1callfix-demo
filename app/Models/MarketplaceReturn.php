<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** E-commerce Phase 3b — a shop return (damaged or wrong item) and its refund under the approval model. */
class MarketplaceReturn extends Model
{
    public const REQUESTED = 'requested';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const RECEIVED = 'received';
    public const REFUNDED = 'refunded';

    /** Statuses that keep the seller's earnings on hold. */
    public const OPEN = [self::REQUESTED, self::ACCEPTED, self::RECEIVED];

    public const REFUND_AWAITING_REQUEST = 'awaiting_request';
    public const REFUND_AWAITING_APPROVAL = 'awaiting_approval';
    public const REFUND_DONE = 'refunded';
    public const REFUND_FAILED = 'failed';
    public const REFUND_OPEN = [self::REFUND_AWAITING_REQUEST, self::REFUND_AWAITING_APPROVAL, self::REFUND_FAILED];

    /** Rider return pickup (shop go-live 2026-10-31). */
    public const PICKUP_OPEN = 'open';
    public const PICKUP_ASSIGNED = 'assigned';
    public const PICKUP_PICKED = 'picked';
    public const PICKUP_DROPPED = 'dropped';
    public const PICKUP_CANCELLED = 'cancelled';
    /** A rider has it, or is on the way to it: only the drop code closes it. */
    public const PICKUP_WITH_RIDER = [self::PICKUP_ASSIGNED, self::PICKUP_PICKED];

    public const REASONS = ['damaged' => 'Damaged', 'wrong_item' => 'Wrong item'];

    /** Food F4: a problem with a food order (nothing comes back). Customer words: SiteText food.issue.reason.*. */
    public const FOOD_REASONS = ['missing' => 'Item missing', 'wrong_item' => 'Wrong item', 'spilled' => 'Spilled or damaged', 'quality' => 'Poor quality'];

    /** Food F4: whose fault, picked by the admin when accepting. Who pays each: Food Settings → Problems & refunds. */
    public const FAULT_RESTAURANT = 'restaurant';
    public const FAULT_TRANSIT = 'transit';
    public const FAULT_UNCLEAR = 'unclear';
    public const FAULTS = [self::FAULT_RESTAURANT => "Restaurant's fault", self::FAULT_TRANSIT => 'Damaged on the way', self::FAULT_UNCLEAR => 'Not clear'];

    protected $fillable = [
        'marketplace_order_id', 'franchise_id', 'store_id', 'customer_id', 'reason', 'description', 'photos', 'items',
        'status', 'decided_by_id', 'decision_note', 'decided_at', 'received_at',
        'refund_amount', 'refund_destination', 'refund_status', 'refund_requested_by_id', 'refund_request_reason',
        'refund_requested_at', 'refund_approved_by_id', 'refund_approval_reason', 'refund_approved_at', 'refund_rejected_at',
        'refund_gateway_id', 'refund_failure_message', 'refunded_at', 'escalation_level', 'last_escalated_at',
        'pickup_status', 'pickup_worker_id', 'pickup_fee', 'pickup_fee_seller', 'pickup_fee_platform', 'pickup_otp', 'drop_otp',
        'pickup_assigned_at', 'picked_up_at', 'dropped_at',
        'fault', 'field_worker_id', 'restaurant_paid', 'platform_paid',
    ];

    protected $casts = [
        'photos' => 'array', 'items' => 'array', 'refund_amount' => 'decimal:2',
        'decided_at' => 'datetime', 'received_at' => 'datetime', 'refund_requested_at' => 'datetime',
        'refund_approved_at' => 'datetime', 'refund_rejected_at' => 'datetime', 'refunded_at' => 'datetime',
        'last_escalated_at' => 'datetime',
        'pickup_fee' => 'decimal:2', 'pickup_fee_seller' => 'decimal:2', 'pickup_fee_platform' => 'decimal:2',
        'pickup_assigned_at' => 'datetime', 'picked_up_at' => 'datetime', 'dropped_at' => 'datetime',
        'restaurant_paid' => 'decimal:2', 'platform_paid' => 'decimal:2',
    ];

    public function order() { return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id'); }
    public function store() { return $this->belongsTo(Store::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function pickupWorker() { return $this->belongsTo(FieldWorker::class, 'pickup_worker_id'); }
    /** Food F4: the rider who delivered the order (a strike when the admin finds it was damaged on the way). */
    public function deliveryWorker() { return $this->belongsTo(FieldWorker::class, 'field_worker_id'); }

    /** Food F4: is this a problem report on a food order (no item comes back)? */
    public function isFoodIssue(): bool
    {
        return $this->order?->isFood() ?? false;
    }

    /** Food F4: rider strikes = food problems the admin found were damaged on the way, in the last N days (Food Settings). */
    public static function riderStrikes(int $fieldWorkerId, ?int $days = null): int
    {
        $days ??= (int) \App\Support\FoodSettings::get('food.rider_strike_days');

        return static::where('field_worker_id', $fieldWorkerId)->where('fault', self::FAULT_TRANSIT)
            ->where('status', '!=', self::DECLINED)
            ->when($days > 0, fn ($q) => $q->where('decided_at', '>=', now()->subDays($days)))->count();
    }

    /** When the current refund clock started: the last rejection, else the item coming back. */
    public function refundClockStartedAt(): Carbon
    {
        return $this->refund_rejected_at ?? $this->received_at ?? $this->created_at;
    }

    public function photoUrls(): array
    {
        return collect($this->photos ?? [])->map(fn ($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p))->all();
    }
}
