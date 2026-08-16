<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'booking_id',
        'parcel_order_id',
        'purpose',
        'user_id',
        'plan_subscription_id',
        'payment_method_id',
        'amount',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'gateway_signature',
        'status',
        'refunded_amount',
        'captured_at'
    ];

    protected $casts = ['captured_at' => 'datetime'];
    public function booking() { return $this->belongsTo(Booking::class); }
    public function method() { return $this->belongsTo(PaymentMethod::class, 'payment_method_id'); }
    /** Only set for purpose = 'wallet_topup' -- booking-purpose payments derive the customer via booking->customer instead. */
    public function user() { return $this->belongsTo(User::class); }
    /** Only set for purpose = 'plan_subscription'. */
    public function planSubscription() { return $this->belongsTo(Subscription::class, 'plan_subscription_id'); }
    /** Phase 22.4 -- only set for purpose = 'parcel_order', following the exact same one-column-per-purpose pattern as planSubscription() above. */
    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
}
