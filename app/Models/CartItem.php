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

    /**
     * Shop checkout polish 2026-11-02: does this line carry the product's discount? The same rule
     * CreateMarketplaceOrderAction uses to decide cash (a variant with its own price has no discount).
     */
    public function isDiscounted(): bool
    {
        $percent = (float) ($this->product?->discount_percent ?? 0);

        return $percent > 0 && ! ($this->variant && $this->variant->price_override !== null);
    }

    /** The price before the discount, per unit (null when the line is not discounted). */
    public function listUnitPrice(): ?float
    {
        return $this->isDiscounted() ? (float) $this->product->price : null;
    }

    /** Today's price per unit (discount taken off), as the cart and checkout show it. */
    public function unitPrice(): float
    {
        return (float) ($this->variant ? $this->variant->effectivePrice() : ($this->product?->effective_price ?? 0));
    }
}
