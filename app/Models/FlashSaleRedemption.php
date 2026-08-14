<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlashSaleRedemption extends Model
{
    use HasFactory;

    protected $table = 'flash_sale_redemptions';

    protected $fillable = [
        'flash_sale_id', 'service_id', 'user_id', 'booking_id',
        'original_price', 'final_price', 'discount_applied',
    ];

    public function flashSale() { return $this->belongsTo(FlashSale::class); }
    public function service() { return $this->belongsTo(Service::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
}
