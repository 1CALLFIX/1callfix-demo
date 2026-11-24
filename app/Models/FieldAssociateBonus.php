<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Field associates release A (owner 2026-11-24): an associate's bonus for one month, the highest level reached
 * (e.g. 10 shops ₹500, 20 shops ₹1,000, 30 shops ₹1,500; highest only, not added together). The amount is the one set
 * when that level was reached.
 */
class FieldAssociateBonus extends Model
{
    protected $fillable = ['field_associate_id', 'month', 'shops', 'level_shops', 'amount', 'earned_at'];

    protected $casts = ['month' => 'date', 'amount' => 'decimal:2', 'earned_at' => 'datetime'];

    public function associate(): BelongsTo
    {
        return $this->belongsTo(FieldAssociate::class, 'field_associate_id');
    }
}
