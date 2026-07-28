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
        'payment_method_id',
        'amount',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'gateway_signature',
        'status',
        'captured_at'
    ];

    protected $casts = ['captured_at' => 'datetime'];
    public function booking() { return $this->belongsTo(Booking::class); }
    public function method() { return $this->belongsTo(PaymentMethod::class, 'payment_method_id'); }
}
