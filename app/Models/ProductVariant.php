<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $table = 'product_variants';

    protected $fillable = ['product_id', 'name', 'sku', 'price_override', 'stock', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function product() { return $this->belongsTo(Product::class); }

    /** Falls back to the parent product's own (discount-applied) price when this variant carries no override. */
    public function effectivePrice(): float
    {
        return $this->price_override !== null ? (float) $this->price_override : $this->product->effective_price;
    }
}
