<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Events E2: one ticket purchase. Online or wallet only. pending (waiting for payment, tickets held) → confirmed
 * (paid, tickets issued) | cancelled (not paid in time, or the event was cancelled).
 */
class EventOrder extends Model
{
    protected $fillable = [
        'code', 'event_id', 'customer_id', 'franchise_id', 'zone_id', 'buyer_name', 'buyer_phone', 'status', 'payment_method',
        'payment_status', 'ticket_count', 'subtotal', 'booking_fee', 'tax_amount', 'platform_collects_gst', 'total',
        'refunded_amount', 'payment_expires_at', 'paid_at', 'cancellation_note',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'booking_fee' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'platform_collects_gst' => 'boolean',
        'payment_expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'ticket_count' => 'integer',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function items() { return $this->hasMany(EventOrderItem::class); }
    public function tickets() { return $this->hasMany(EventTicket::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    public function isAwaitingOnlinePayment(): bool
    {
        return $this->status === 'pending' && $this->payment_status === 'pending' && $this->payment_method === 'online';
    }

    /** EVT-1311-XXXXXX: readable on the phone and at the gate. */
    public static function newCode(): string
    {
        do {
            $code = 'EVT-'.now()->format('dm').'-'.strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
