<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** REF 1CF-CANCEL-POLICY-001 — the in-app price quote a professional sends the customer. */
class BookingQuote extends Model
{
    protected $fillable = ['booking_id', 'provider_id', 'amount', 'status', 'sent_at', 'responded_at'];

    protected $casts = ['amount' => 'decimal:2', 'sent_at' => 'datetime', 'responded_at' => 'datetime'];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function provider() { return $this->belongsTo(Provider::class); }
}
