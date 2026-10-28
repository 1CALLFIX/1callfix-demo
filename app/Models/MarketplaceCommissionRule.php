<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** E-commerce Phase 4 — one platform-fee % for a shop / category / franchise / city combination (blank = any). */
class MarketplaceCommissionRule extends Model
{
    protected $fillable = ['store_id', 'marketplace_category_id', 'franchise_id', 'city_id', 'platform_fee_percent', 'created_by'];

    protected $casts = ['platform_fee_percent' => 'decimal:2'];

    public function store() { return $this->belongsTo(Store::class); }
    public function category() { return $this->belongsTo(MarketplaceCategory::class, 'marketplace_category_id'); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function city() { return $this->belongsTo(City::class); }
}
