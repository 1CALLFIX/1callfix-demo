<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One queue row per Razorpay payment whose captured amount did not match. See MismatchRefundService. */
class MismatchRefund extends Model
{
    public const AWAITING_REQUEST = 'awaiting_request';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const REFUNDED = 'refunded';

    public const FAILED = 'failed';

    /** Statuses that still need a human. */
    public const OPEN = [self::AWAITING_REQUEST, self::AWAITING_APPROVAL, self::FAILED];

    protected $fillable = [
        'payment_webhook_log_id', 'payment_id', 'gateway_payment_id', 'franchise_id', 'amount_paise', 'status',
        'requested_by_id', 'request_reason', 'requested_at', 'approved_by_id', 'approval_reason', 'approved_at',
        'refunded_at', 'gateway_refund_id', 'failure_message', 'escalation_level', 'last_escalated_at', 'refund_notice_sent_at',
    ];

    protected $casts = [
        'amount_paise' => 'integer',
        'escalation_level' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'refunded_at' => 'datetime',
        'last_escalated_at' => 'datetime',
        'refund_notice_sent_at' => 'datetime',
    ];

    public function webhookLog() { return $this->belongsTo(PaymentWebhookLog::class, 'payment_webhook_log_id'); }

    public function payment() { return $this->belongsTo(Payment::class); }

    public function franchise() { return $this->belongsTo(Franchise::class); }

    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by_id'); }

    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by_id'); }

    public function amountRupees(): float
    {
        return round($this->amount_paise / 100, 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
