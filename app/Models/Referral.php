<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Referral extends Model
{
    use HasFactory;

    protected $table = 'referrals';

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'qualifying_booking_id',
        'expires_at',
        'reward_amount',
        'status',
        'fraud_flagged_at',
        'fraud_flagged_by',
        'fraud_notes',
        'reversed_at',
        'reversal_note',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'fraud_flagged_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
    public function qualifyingBooking() { return $this->belongsTo(Booking::class, 'qualifying_booking_id'); }
    public function fraudFlaggedBy() { return $this->belongsTo(User::class, 'fraud_flagged_by'); }
}
