<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class SosAlert extends Model
{
    use HasFactory;

    protected $table = 'sos_alerts';

    protected $fillable = [
        'booking_id',
        'raised_by',
        'lat',
        'lng',
        'status'
    ];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function raisedBy() { return $this->belongsTo(User::class, 'raised_by'); }
}
