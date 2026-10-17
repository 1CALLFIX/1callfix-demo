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
        // EARN4
        'signup_ip',
        'signup_device_id',
        'hold_reason',
        'no_reward_reason',
        'reviewed_at',
        'reviewed_by',
        'review_note',
        'referee_reward_amount',
        'referee_reward_points',
        'referee_rewarded_at',
    ];

    /**
     * signup_ip / signup_device_id are personal data (EARN4): never
     * serialised — Super Admin screens read the attributes explicitly.
     */
    protected $hidden = ['signup_ip', 'signup_device_id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'fraud_flagged_at' => 'datetime',
        'reversed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'referee_rewarded_at' => 'datetime',
    ];

    public function referrer() { return $this->belongsTo(User::class, 'referrer_id'); }
    public function referred() { return $this->belongsTo(User::class, 'referred_id'); }
    public function qualifyingBooking() { return $this->belongsTo(Booking::class, 'qualifying_booking_id'); }
    public function fraudFlaggedBy() { return $this->belongsTo(User::class, 'fraud_flagged_by'); }
    public function reviewedBy() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
