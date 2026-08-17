<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Review extends Model
{
    use HasFactory;

    protected $table = 'reviews';

    protected $fillable = [
        'booking_id',
        'property_reservation_id',
        'marketplace_order_id',
        'rental_reservation_id',
        'hotel_reservation_id',
        'customer_id',
        'provider_id',
        'rating',
        'comment',
        'provider_reply'
    ];

    public function booking() { return $this->belongsTo(Booking::class); }
    /** Phase 22.7 -- the Property Rental counterpart to booking(). At most one of booking_id/property_reservation_id/marketplace_order_id is set. */
    public function propertyReservation() { return $this->belongsTo(PropertyReservation::class); }
    /** Phase 24 (Marketplace Foundation) -- the Marketplace counterpart. */
    public function marketplaceOrder() { return $this->belongsTo(MarketplaceOrder::class); }
    /** RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine counterpart. */
    public function rentalReservation() { return $this->belongsTo(RentalReservation::class); }
    /** HOTEL / STAY BOOKING MODULE -- the Hotel counterpart. Deliberately unused by any writer today -- see HOTEL_MODULE_ARCHITECTURE.md's explicit "reviews deferred" scope note. */
    public function hotelReservation() { return $this->belongsTo(HotelReservation::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function provider() { return $this->belongsTo(Provider::class); }
}
