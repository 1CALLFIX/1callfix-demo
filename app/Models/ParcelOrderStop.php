<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Parcel Stage 2 (owner decision 2026-10-31): drop 2, 3, ... of a multi-drop order. Drop 1 is the order's own
 * dropoff address / recipient / delivery code; each extra stop has its own recipient and delivery code.
 */
class ParcelOrderStop extends Model
{
    protected $table = 'parcel_order_stops';

    protected $fillable = [
        'parcel_order_id', 'sequence', 'address_id', 'recipient_name', 'recipient_phone', 'delivery_otp', 'delivered_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
    public function address() { return $this->belongsTo(Address::class); }
}
