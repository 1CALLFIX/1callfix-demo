<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Commission extends Model
{
    use HasFactory;

    protected $table = 'commissions';

    protected $fillable = [
        'booking_id',
        'parcel_order_id',
        'taxi_ride_id',
        'provider_commission',
        'franchise_commission',
        'platform_commission'
    ];
    public function booking() { return $this->belongsTo(Booking::class); }

    /** Phase 22.4 -- the Parcel counterpart to booking(). Exactly one of booking_id/parcel_order_id/taxi_ride_id is ever set on a given row. */
    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }

    /** Phase 22.6 -- the Taxi counterpart. */
    public function taxiRide() { return $this->belongsTo(TaxiRide::class); }
}
