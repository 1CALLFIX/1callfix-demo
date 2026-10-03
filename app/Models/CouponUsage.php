<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One redemption. status: reserved (booking created) -> confirmed (a job
 * completed) | released (cancelled before work) | consumed (cancelled once
 * work had started: counts toward limits, discount forfeited).
 */
class CouponUsage extends Model
{
    use HasFactory;

    /** Statuses that still count toward usage / per-user / budget limits. */
    public const COUNTING = ['reserved', 'confirmed', 'consumed'];

    protected $table = 'coupon_usages';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'booking_id',
        'booking_bundle_id',
        'discount_applied',
        'status',
        'original_amount',
        'net_amount',
        'snapshot',
        'reserved_at',
        'confirmed_at',
        'released_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'reserved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function coupon() { return $this->belongsTo(Coupon::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
    public function bundle() { return $this->belongsTo(BookingBundle::class, 'booking_bundle_id'); }
}
