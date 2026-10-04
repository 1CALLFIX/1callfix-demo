<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's overpricing complaint raised after payment (A2). One admin queue row; the refund decision, if any,
 * runs through BookingDisputeService (the manual-money approval model).
 */
class BookingDispute extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const OUTCOMES = ['no_change' => 'No change', 'refund' => 'Refund part of the amount', 'adjusted_elsewhere' => 'Settled another way'];

    public const REFUND_AWAITING_REQUEST = 'awaiting_request';

    public const REFUND_AWAITING_APPROVAL = 'awaiting_approval';

    public const REFUNDED = 'refunded';

    public const REFUND_FAILED = 'failed';

    public const REFUND_OPEN = [self::REFUND_AWAITING_REQUEST, self::REFUND_AWAITING_APPROVAL, self::REFUND_FAILED];

    protected $guarded = [];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'provider_share' => 'decimal:2',
        'company_share' => 'decimal:2',
        'provider_recovered' => 'decimal:2',
        'escalation_level' => 'integer',
        'resolved_at' => 'datetime',
        'refund_requested_at' => 'datetime',
        'refund_approved_at' => 'datetime',
        'refund_rejected_at' => 'datetime',
        'refunded_at' => 'datetime',
        'last_escalated_at' => 'datetime',
    ];

    public const BEARERS = ['provider' => 'Provider pays', 'company' => 'Company pays', 'split' => 'Split'];

    public function debt() { return $this->hasOne(ProviderDisputeDebt::class, 'booking_dispute_id'); }

    public function booking() { return $this->belongsTo(Booking::class); }

    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }

    public function franchise() { return $this->belongsTo(Franchise::class); }

    public function resolvedBy() { return $this->belongsTo(User::class, 'resolved_by_id'); }

    public function requestedBy() { return $this->belongsTo(User::class, 'refund_requested_by_id'); }

    public function approvedBy() { return $this->belongsTo(User::class, 'refund_approved_by_id'); }

    /** Queue age for the money action starts at the latest rejection, else when the refund was decided. */
    public function refundClockStartedAt(): \Illuminate\Support\Carbon
    {
        return $this->refund_rejected_at ?? $this->resolved_at ?? $this->created_at;
    }
}
