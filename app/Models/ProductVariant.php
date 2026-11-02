<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One combination of a product's choices (e.g. Size M / Colour Red, or Portion Half). `options` holds the picks,
 * `name` the readable label ("M / Red") that the cart, order and invoice keep. Its own price (`price_override`)
 * replaces the product price; the product's discount applies to it too (product choices 2026-11-02). Its own stock is
 * the real stock; the product's `stock` is kept as the total of its active choices so every list keeps working.
 */
class ProductVariant extends Model
{
    use HasFactory;

    protected $table = 'product_variants';

    protected $fillable = ['product_id', 'name', 'options', 'image', 'sku', 'price_override', 'stock', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'options' => 'array'];

    protected static function booted(): void
    {
        $sync = fn (ProductVariant $v) => $v->product?->syncStockFromChoices();
        // created/updated (not saved): a model decrement() at checkout fires only `updated`.
        static::created($sync);
        static::updated($sync);
        static::deleted($sync);
    }

    public function product() { return $this->belongsTo(Product::class); }

    /** The price before the discount: its own price, or the product's. */
    public function listPrice(): float
    {
        return $this->price_override !== null ? (float) $this->price_override : (float) $this->product->price;
    }

    /** Today's price: the product's discount taken off this choice's price, never negative. */
    public function effectivePrice(): float
    {
        return $this->product->discountedPrice($this->listPrice());
    }

    /** The photo for this choice (a URL), or null. */
    public function imageUrl(): ?string
    {
        return $this->image ? Product::photoUrl($this->image) : null;
    }
}
