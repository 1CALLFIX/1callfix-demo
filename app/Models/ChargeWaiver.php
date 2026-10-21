<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One waiver of a customer charge through the manual money approval model (see ChargeWaiverService). */
class ChargeWaiver extends Model
{
    public const AWAITING = 'awaiting_approval';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const KINDS = [
        'cancellation_request' => 'Cancellation charge',
        'cancel_fee' => 'Admin-cancel fee',
        'interim_reduction' => 'Disputed work-done amount lowered',
    ];

    protected $fillable = [
        'booking_id', 'franchise_id', 'kind', 'cancellation_request_id', 'amount', 'payload', 'status',
        'requested_by_id', 'request_reason', 'requested_at', 'approved_by_id', 'approval_reason', 'approved_at',
        'rejected_by_id', 'rejection_reason', 'rejected_at', 'failure_message', 'escalation_level', 'last_escalated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'last_escalated_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
