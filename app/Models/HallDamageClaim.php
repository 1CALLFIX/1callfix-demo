<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Function halls R4: a damage claim on a hall deposit, decided under the manual money approval model
 * (HallDamageClaimService). open → awaiting_approval → settled | failed; a rejected request goes back to open.
 */
class HallDamageClaim extends Model
{
    public const OPEN = 'open';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const SETTLED = 'settled';
    public const FAILED = 'failed';

    /** Still waiting for someone (the queue, escalation). */
    public const WAITING = [self::OPEN, self::AWAITING_APPROVAL, self::FAILED];

    public const STATUS_LABELS = [
        self::OPEN => 'Waiting for a decision', self::AWAITING_APPROVAL => 'Waiting for a second approval',
        self::SETTLED => 'Settled', self::FAILED => 'Payment failed: retry',
    ];

    protected $fillable = [
        'hall_booking_id', 'franchise_id', 'deposit_amount', 'asked_amount', 'note', 'status', 'hall_amount',
        'requested_by_id', 'request_reason', 'requested_at', 'approved_by_id', 'approval_reason', 'approved_at',
        'rejected_at', 'settled_at', 'failure_message', 'escalation_level', 'last_escalated_at',
    ];

    protected $casts = [
        'deposit_amount' => 'decimal:2',
        'asked_amount' => 'decimal:2',
        'hall_amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'settled_at' => 'datetime',
        'last_escalated_at' => 'datetime',
        'escalation_level' => 'integer',
    ];

    public function booking() { return $this->belongsTo(HallBooking::class, 'hall_booking_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by_id'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by_id'); }

    /** The escalation clock: from the request when one is waiting for approval, otherwise from the claim. */
    public function clockStartedAt(): \Illuminate\Support\Carbon
    {
        return $this->status === self::AWAITING_APPROVAL && $this->requested_at ? $this->requested_at : $this->created_at;
    }
}
