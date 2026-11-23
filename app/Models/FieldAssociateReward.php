<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reward a field associate earned for one shop: 'shop_live' or 'first_order', with the amount at that time. */
class FieldAssociateReward extends Model
{
    public const SHOP_LIVE = 'shop_live';

    public const FIRST_ORDER = 'first_order';

    protected $fillable = ['field_associate_id', 'store_id', 'kind', 'amount', 'earned_at'];

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
