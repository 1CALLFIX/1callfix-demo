<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reward a field associate earned for one shop or restaurant, with the amount at that time: 'shop_live',
 * 'products' (10 approved products / menu items, release A 2026-11-24) or 'first_order' (with its order and customer).
 */
class FieldAssociateReward extends Model
{
    public const SHOP_LIVE = 'shop_live';

    public const PRODUCTS = 'products';

    public const FIRST_ORDER = 'first_order';

    protected $fillable = ['field_associate_id', 'store_id', 'kind', 'order_id', 'customer_id', 'amount', 'earned_at'];

    protected $casts = ['amount' => 'decimal:2', 'earned_at' => 'datetime'];

    public function associate(): BelongsTo
    {
        return $this->belongsTo(FieldAssociate::class, 'field_associate_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }
}
