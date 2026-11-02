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
        'option_names', 'unit_value', 'unit',
        'is_approved', 'is_active',
    ];

    protected $casts = [
        'images' => 'array',
        'option_names' => 'array',
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
        return collect($this->images ?? [])->filter()->map(fn ($path) => self::photoUrl($path))->values()->all();
    }

    /** A stored photo path (public disk) or a full URL from a CSV import, as a URL. */
    public static function photoUrl(string $path): string
    {
        return Str::startsWith($path, ['http://', 'https://', '//', 'data:'])
            ? $path
            : \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }

    public function store() { return $this->belongsTo(Store::class); }
    public function category() { return $this->belongsTo(MarketplaceCategory::class, 'marketplace_category_id'); }
    public function variants() { return $this->hasMany(ProductVariant::class); }
    public function activeVariants() { return $this->hasMany(ProductVariant::class)->where('is_active', true)->orderBy('id'); }

    /**
     * Product choices 2026-11-02: does the customer pick a choice (size, colour, portion…) before adding it?
     * Only when the product has choice types and at least one combination on sale.
     */
    public function hasChoices(): bool
    {
        if (empty($this->option_names)) {
            return false;
        }

        return $this->relationLoaded('activeVariants') ? $this->activeVariants->isNotEmpty() : $this->activeVariants()->exists();
    }

    /** The lowest price among the choices on sale (for "From ₹…"), or the product's own price. */
    public function fromPrice(): float
    {
        if (! $this->hasChoices()) {
            return $this->effective_price;
        }
        $this->loadMissing('activeVariants');
        $inStock = $this->activeVariants->where('stock', '>', 0);

        return (float) ($inStock->isNotEmpty() ? $inStock : $this->activeVariants)->each->setRelation('product', $this)
            ->map(fn (ProductVariant $v) => $v->effectivePrice())->min();
    }

    /** Do the choices on sale have different prices? ("From ₹…" only then.) */
    public function choicePricesDiffer(): bool
    {
        if (! $this->hasChoices()) {
            return false;
        }
        $this->loadMissing('activeVariants');

        return $this->activeVariants->map(fn (ProductVariant $v) => $v->price_override === null ? null : (string) (float) $v->price_override)
            ->unique()->count() > 1;
    }

    /** The product's stock is the total of its active choices (so lists, badges and search engines stay right). */
    public function syncStockFromChoices(): void
    {
        if (empty($this->option_names)) {
            return;
        }
        $total = (int) $this->variants()->where('is_active', true)->sum('stock');
        if ((int) $this->stock !== $total) {
            $this->forceFill(['stock' => $total])->saveQuietly();
        }
    }

    /** What one item is, e.g. "500 g", "1 kg", "6 piece" — or null. */
    public function unitLabel(): ?string
    {
        if (blank($this->unit)) {
            return null;
        }
        $value = $this->unit_value !== null ? rtrim(rtrim(number_format((float) $this->unit_value, 3, '.', ''), '0'), '.') : '1';

        return $value.' '.$this->unit;
    }

    /** A price with this product's discount taken off, never negative. */
    public function discountedPrice(float $price): float
    {
        if ($this->discount_percent) {
            $price -= round($price * (float) $this->discount_percent / 100, 2);
        }

        return max($price, 0);
    }

    /** The price a customer actually sees before any variant/add-on adjustment -- discount_percent applied, never a negative result. */
    public function getEffectivePriceAttribute(): float
    {
        return $this->discountedPrice((float) $this->price);
    }
}
