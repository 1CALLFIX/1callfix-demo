<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class DispatchAttempt extends Model
{
    use HasFactory;

    protected $table = 'dispatch_attempts';

    protected $fillable = [
        'booking_id',
        'provider_id',
        'status',
        'distance_km',
        'notified_at',
        'responded_at'
    ];

    protected $casts = ['notified_at' => 'datetime', 'responded_at' => 'datetime'];
    public function booking() { return $this->belongsTo(Booking::class); }
    public function provider() { return $this->belongsTo(Provider::class); }
}
