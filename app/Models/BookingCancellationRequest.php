<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** REF 1CF-CANCEL-POLICY-001 — a customer cancellation that must be settled (paid) before it completes. */
class BookingCancellationRequest extends Model
{
    protected $fillable = [
        'booking_id', 'requested_by', 'status', 'total_charge', 'amount_due', 'basis', 'reason',
        'payment_id', 'due_by', 'flagged_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = ['basis' => 'array', 'due_by' => 'datetime', 'flagged_at' => 'datetime'];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
}
