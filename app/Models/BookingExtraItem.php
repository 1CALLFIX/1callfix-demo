<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingExtraItem extends Model
{
    protected $table = 'booking_extra_items';

    protected $fillable = [
        'booking_id',
        'description',
        'amount',
        'status',
        'added_by_provider_id',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function addedByProvider()
    {
        return $this->belongsTo(Provider::class, 'added_by_provider_id');
    }
}
