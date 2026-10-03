<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Include/exclude rule row. target_type is a registry key, see App\Services\Coupons\TargetMatcher. */
class CouponTarget extends Model
{
    protected $table = 'coupon_targets';

    protected $fillable = ['coupon_id', 'target_type', 'target_id', 'operator', 'params'];

    protected $casts = ['params' => 'array'];

    public function coupon() { return $this->belongsTo(Coupon::class); }
}
