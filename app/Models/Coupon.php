<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Coupon engine (docs/COUPON_ENGINE_DESIGN.md). Redemption lives in
 * App\Services\Coupons\CouponService; this model is data only.
 *
 * `status` is the source of truth (draft/active/paused/exhausted/expired);
 * `is_active` is kept in sync for the legacy column.
 */
class Coupon extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUSES = ['draft', 'active', 'paused', 'exhausted', 'expired'];

    protected $table = 'coupons';

    protected $fillable = [
        'franchise_id',
        'code',
        'name',
        'description',
        'status',
        'module',
        'discount_type',
        'value',
        'min_order_value',
        'max_discount',
        'usage_limit',
        'per_user_limit',
        'total_budget',
        'stackable_with_flash',
        'valid_from',
        'valid_until',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'stackable_with_flash' => 'boolean',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function targets() { return $this->hasMany(CouponTarget::class); }
    public function usages() { return $this->hasMany(CouponUsage::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
