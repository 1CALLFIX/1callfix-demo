<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Parcel Stage 2 — a protection claim (lost or damaged parcel) and its payout under the approval model. */
class ParcelProtectionClaim extends Model
{
    public const REQUESTED = 'requested';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const PAID = 'paid';

    public const OPEN = [self::REQUESTED, self::ACCEPTED];

    public const PAYOUT_AWAITING_REQUEST = 'awaiting_request';
    public const PAYOUT_AWAITING_APPROVAL = 'awaiting_approval';
    public const PAYOUT_DONE = 'paid';
    public const PAYOUT_FAILED = 'failed';
    public const PAYOUT_OPEN = [self::PAYOUT_AWAITING_REQUEST, self::PAYOUT_AWAITING_APPROVAL, self::PAYOUT_FAILED];

    public const REASONS = ['lost' => 'Lost', 'damaged' => 'Damaged'];

    protected $fillable = [
        'parcel_order_id', 'franchise_id', 'customer_id', 'reason', 'description', 'photos', 'amount_claimed',
        'status', 'decided_by_id', 'decision_note', 'decided_at',
        'payout_amount', 'payout_status', 'payout_requested_by_id', 'payout_request_reason', 'payout_requested_at',
        'payout_approved_by_id', 'payout_approval_reason', 'payout_approved_at', 'payout_rejected_at',
        'payout_failure_message', 'paid_at', 'escalation_level', 'last_escalated_at',
    ];

    protected $casts = [
        'photos' => 'array', 'amount_claimed' => 'decimal:2', 'payout_amount' => 'decimal:2',
        'decided_at' => 'datetime', 'payout_requested_at' => 'datetime', 'payout_approved_at' => 'datetime',
        'payout_rejected_at' => 'datetime', 'paid_at' => 'datetime', 'last_escalated_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(ParcelOrder::class, 'parcel_order_id'); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }

    /** When the current payout clock started: the last rejection, else the decision. */
    public function payoutClockStartedAt(): Carbon
    {
        return $this->payout_rejected_at ?? $this->decided_at ?? $this->created_at;
    }

    /** Photos are on the private disk; admins view them through ParcelClaimPhotoController. */
    public function photoPaths(): array
    {
        return array_values(array_filter($this->photos ?? [], fn ($p) => Storage::disk('local')->exists($p)));
    }
}
