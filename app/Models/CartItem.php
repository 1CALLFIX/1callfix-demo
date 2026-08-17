<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Phase 24 (Marketplace Foundation) — one row IS a cart line item (no separate Cart "header" row, matching real 6amMart evidence). */
class CartItem extends Model
{
    use HasFactory;

    protected $table = 'cart_items';

    protected $fillable = ['user_id', 'store_id', 'product_id', 'product_variant_id', 'quantity', 'add_on_ids', 'unit_price_snapshot'];

    protected $casts = ['add_on_ids' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
    public function store() { return $this->belongsTo(Store::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
