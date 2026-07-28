<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Coupon extends Model
{
    use HasFactory;

    protected $table = 'coupons';

    protected $fillable = [
        'franchise_id',
        'code',
        'discount_type',
        'value',
        'min_order_value',
        'max_discount',
        'usage_limit',
        'per_user_limit',
        'valid_from',
        'valid_until',
        'is_active'
    ];
    protected $casts = ['valid_from' => 'datetime', 'valid_until' => 'datetime'];
    public function franchise() { return $this->belongsTo(Franchise::class); }
}
