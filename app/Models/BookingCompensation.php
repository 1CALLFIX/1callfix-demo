<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCompensation extends Model
{
    protected $table = 'booking_compensations';

    protected $fillable = [
        'booking_id', 'provider_id', 'type', 'amount', 'computed_basis',
        'status', 'applied_by', 'wallet_transaction_ref',
    ];

    protected $casts = ['amount' => 'decimal:2', 'computed_basis' => 'array'];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function appliedBy() { return $this->belongsTo(User::class, 'applied_by'); }
}
