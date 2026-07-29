<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'bookings';

    protected $fillable = [
        'code',
        'franchise_id',
        'zone_id',
        'customer_id',
        'provider_id',
        'service_id',
        'address_id',
        'status',
        'scheduled_at',
        'price_quoted',
        'price_final',
        'payment_status',
        'payment_method',
        'coupon_id',
        'cancellation_reason_id',
        'cancellation_note',
        'customer_note',
        'start_otp',
        'completion_otp',
        'completed_at',
        'hold_category',
        'hold_reason',
        'hold_note',
        'on_hold_since'
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'on_hold_since' => 'datetime'];
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function service() { return $this->belongsTo(Service::class); }
    public function address() { return $this->belongsTo(Address::class); }
    public function options() { return $this->hasMany(BookingOption::class); }
    public function statusHistory() { return $this->hasMany(BookingStatusHistory::class); }
    public function dispatchAttempts() { return $this->hasMany(DispatchAttempt::class); }
    public function payment() { return $this->hasOne(Payment::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function review() { return $this->hasOne(Review::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
}
