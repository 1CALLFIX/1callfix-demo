<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class BookingOption extends Model
{
    use HasFactory;

    protected $table = 'booking_options';

    protected $fillable = [
        'booking_id',
        'service_option_id',
        'price_delta'
    ];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function option() { return $this->belongsTo(ServiceOption::class, 'service_option_id'); }
}
