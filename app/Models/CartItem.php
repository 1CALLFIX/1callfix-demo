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
     * CreateMarketplaceOrderAction uses to decide cash. Product choices 2026-11-02: a choice with its own price
     * gets the product's discount too, so any discount on the product counts.
     */
    public function isDiscounted(): bool
    {
        return (float) ($this->product?->discount_percent ?? 0) > 0;
    }

    /** The price before the discount, per unit (null when the line is not discounted). */
    public function listUnitPrice(): ?float
    {
        if (! $this->isDiscounted()) {
            return null;
        }

        return $this->variant ? $this->variant->listPrice() : (float) $this->product->price;
    }

    /** Today's price per unit (discount taken off), as the cart and checkout show it. */
    public function unitPrice(): float
    {
        return (float) ($this->variant ? $this->variant->effectivePrice() : ($this->product?->effective_price ?? 0));
    }
}
