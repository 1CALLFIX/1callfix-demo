<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class BookingStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'booking_status_history';

    protected $fillable = [
        'booking_id',
        'status',
        'changed_by',
        'note',
        'changed_at'
    ];

    protected $casts = ['changed_at' => 'datetime'];
    public function booking() { return $this->belongsTo(Booking::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
