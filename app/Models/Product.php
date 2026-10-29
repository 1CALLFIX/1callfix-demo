<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Phase 24 (Marketplace Foundation) — the catalog listing. `stock` is authoritative only when the product has no active variants (see ProductVariant). */
class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'external_id', 'store_id', 'marketplace_category_id', 'name', 'slug', 'description', 'images',
        'price', 'tax_percent', 'discount_percent', 'stock', 'maximum_cart_quantity',
        'is_approved', 'is_active',
    ];

    protected $casts = [
        'images' => 'array',
        'is_approved' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Product $product) {
            // Link rule: a clean, readable slug; -2, -3 only on collision (never a random suffix).
            if (empty($product->slug)) {
                $base = Str::slug($product->name) ?: 'product';
                $slug = $base;
                for ($n = 2; static::withTrashed()->where('slug', $slug)->exists(); $n++) {
                    $slug = $base.'-'.$n;
                }
                $product->slug = $slug;
            }
        });
    }

    /** Public URLs of the product photos (stored paths on the public disk, or full URLs from a CSV import). */
    public function getImageUrlsAttribute(): array
    {
        return collect($this->images ?? [])->filter()->map(fn ($path) => Str::startsWith($path, ['http://', 'https://', '//', 'data:'])
            ? $path
            : \Illuminate\Support\Facades\Storage::disk('public')->url($path))->values()->all();
    }

    public function store() { return $this->belongsTo(Store::class); }
    public function category() { return $this->belongsTo(MarketplaceCategory::class, 'marketplace_category_id'); }
    public function variants() { return $this->hasMany(ProductVariant::class); }

    /** The price a customer actually sees before any variant/add-on adjustment -- discount_percent applied, never a negative result. */
    public function getEffectivePriceAttribute(): float
    {
        $price = (float) $this->price;

        if ($this->discount_percent) {
            $price -= round($price * (float) $this->discount_percent / 100, 2);
        }

        return max($price, 0);
    }
}
