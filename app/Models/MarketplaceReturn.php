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

    public const REASONS = ['damaged' => 'Damaged', 'wrong_item' => 'Wrong item'];

    protected $fillable = [
        'marketplace_order_id', 'franchise_id', 'store_id', 'customer_id', 'reason', 'description', 'photos', 'items',
        'status', 'decided_by_id', 'decision_note', 'decided_at', 'received_at',
        'refund_amount', 'refund_destination', 'refund_status', 'refund_requested_by_id', 'refund_request_reason',
        'refund_requested_at', 'refund_approved_by_id', 'refund_approval_reason', 'refund_approved_at', 'refund_rejected_at',
        'refund_gateway_id', 'refund_failure_message', 'refunded_at', 'escalation_level', 'last_escalated_at',
    ];

    protected $casts = [
        'photos' => 'array', 'items' => 'array', 'refund_amount' => 'decimal:2',
        'decided_at' => 'datetime', 'received_at' => 'datetime', 'refund_requested_at' => 'datetime',
        'refund_approved_at' => 'datetime', 'refund_rejected_at' => 'datetime', 'refunded_at' => 'datetime',
        'last_escalated_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id'); }
    public function store() { return $this->belongsTo(Store::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }

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
