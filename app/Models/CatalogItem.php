<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One item of the 1CallFix master product list (owner 2026-11-03). Sellers pick from it; HQ owns it. */
class CatalogItem extends Model
{
    protected $fillable = ['module', 'category', 'sub_category', 'name', 'phase', 'marketplace_category_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function marketplaceCategory()
    {
        return $this->belongsTo(MarketplaceCategory::class);
    }
}
