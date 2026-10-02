<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** REF 1CF-CANCEL-POLICY-001 — one in-app call attempt to the customer (backs the "customer unreachable" cancel). */
class BookingCallAttempt extends Model
{
    protected $fillable = ['booking_id', 'provider_id', 'attempted_at'];

    protected $casts = ['attempted_at' => 'datetime'];

    public function booking() { return $this->belongsTo(Booking::class); }
}
