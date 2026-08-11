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
        'reward_amount',
        'status'
    ];

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
    public function qualifyingBooking() { return $this->belongsTo(Booking::class, 'qualifying_booking_id'); }
}
