<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class LoyaltyPoint extends Model
{
    use HasFactory;

    protected $table = 'loyalty_points';

    protected $fillable = [
        'user_id',
        'points',
        'reason',
        'booking_id',
        'expires_at'
    ];

    protected $casts = ['expires_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
}
