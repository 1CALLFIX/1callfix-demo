<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Function halls R2: one date + slot of one hall. Online or wallet only.
 * pending_payment (slot held while paying) → awaiting_owner (paid, the owner accepts within Hall Settings' hours)
 * → confirmed → completed; declined (owner said no, or did not answer: refunded in full) | expired (not paid in time)
 * | cancelled.
 */
class HallBooking extends Model
{
    /** Statuses that keep the slot taken. */
    public const HOLDING = ['pending_payment', 'awaiting_owner', 'confirmed'];

    public const STATUS_LABELS = [
        'pending_payment' => 'Waiting for payment', 'awaiting_owner' => 'Waiting for the hall', 'confirmed' => 'Confirmed',
        'completed' => 'Completed', 'declined' => 'Not accepted', 'expired' => 'Not paid in time', 'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'code', 'hall_id', 'hall_slot_id', 'customer_id', 'franchise_id', 'zone_id', 'event_date', 'days', 'last_date', 'slot_name', 'starts_at',
        'ends_at', 'occasion', 'guests', 'customer_name', 'customer_phone', 'notes', 'status', 'payment_method',
        'payment_status', 'hall_price', 'gst_percent', 'gst_amount', 'platform_collects_gst', 'booking_fee', 'total',
        'deposit_amount', 'pay_now', 'paid_in_full', 'balance_due_on', 'paid_amount', 'refunded_amount',
        'payment_expires_at', 'paid_at', 'accept_by', 'accepted_at', 'declined_at', 'decline_reason', 'cancelled_at',
        'cancellation_reason', 'cancelled_by',
        // R3
        'advance_amount', 'balance_payment_id', 'balance_paid_at', 'balance_reminded_on', 'deposit_status', 'deposit_returned_at',
        'damage_note', 'damage_reported_at', 'kept_amount', 'completed_at',
    ];

    protected $casts = [
        'event_date' => 'date',
        'last_date' => 'date',
        'days' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'balance_due_on' => 'date',
        'payment_expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'accept_by' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'platform_collects_gst' => 'boolean',
        'paid_in_full' => 'boolean',
        'hall_price' => 'decimal:2',
        'gst_percent' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'booking_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'pay_now' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'guests' => 'integer',
        'advance_amount' => 'decimal:2',
        'kept_amount' => 'decimal:2',
        'balance_paid_at' => 'datetime',
        'balance_reminded_on' => 'date',
        'deposit_returned_at' => 'datetime',
        'damage_reported_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function hall() { return $this->belongsTo(Hall::class); }
    public function slot() { return $this->belongsTo(HallSlot::class, 'hall_slot_id'); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    public function isAwaitingOnlinePayment(): bool
    {
        return $this->status === 'pending_payment' && $this->payment_status === 'pending' && $this->payment_method === 'online';
    }

    /** What is still to pay later: the rest of the total, plus the deposit (paid with the balance). 0 once paid. */
    public function balanceLater(): float
    {
        if ($this->paid_in_full || $this->balance_paid_at !== null) {
            return 0.0;
        }

        return round(max(0, (float) $this->total + (float) $this->deposit_amount - (float) $this->pay_now), 2);
    }

    /** The balance can be paid now: confirmed, not yet paid, the event not started. */
    public function balanceOpen(): bool
    {
        return $this->status === 'confirmed' && $this->balanceLater() > 0 && $this->starts_at->isFuture();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? Str::headline((string) $this->status);
    }

    /** HB-1311-XXXXXX: readable on the phone. */
    public static function newCode(): string
    {
        do {
            $code = 'HB-'.now()->format('dm').'-'.strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** "Sat, 14 Nov 2026" or, for a booking of several days, "Sat, 14 Nov – Sun, 15 Nov 2026 (2 days)". */
    public function dateLabel(string $format = 'D, j M Y'): string
    {
        $days = max(1, (int) $this->days);
        if ($days === 1 || $this->last_date === null) {
            return (string) $this->event_date?->format($format);
        }

        return $this->event_date->format('D, j M').' – '.$this->last_date->format($format).' ('.$days.' days)';
    }
}
