<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceOrderItem extends Model
{
    protected $table = 'marketplace_order_items';

    protected $fillable = [
        'marketplace_order_id', 'product_id', 'product_variant_id',
        'product_name_snapshot', 'variant_name_snapshot', 'add_ons_snapshot',
        'quantity', 'unit_price', 'add_ons_total', 'line_total',
    ];

    protected $casts = ['add_ons_snapshot' => 'array'];

    public function order() { return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
