<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'services';

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'external_id',
        'name',
        'slug',
        'description',
        'base_price',
        'discount_price',
        'price_type',
        'duration_estimate_mins',
        'cover_image',
        'is_active',
        'location_required',
        'age_restriction',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'location_required' => 'boolean',
        'age_restriction' => 'boolean',
    ];

    /**
     * A price already resolved for this row by
     * FlashSaleService::effectivePricesFor(), batched for a whole page.
     *
     * Not a column and never persisted — a per-request carrier so a whole
     * collection can be priced in a fixed number of queries and each
     * ServiceResource can then read its own answer without going back to
     * the database (the N+1 CatalogPresenter's batching exists to avoid).
     */
    private ?float $preresolvedEffectivePrice = null;

    /**
     * `quote_on_inspection` shows as "Starts From" in the admin UI — Glover's
     * wording, and the clearer phrasing for what it means to a customer (the
     * price shown isn't final). The stored value stays as-is.
     */
    public const PRICE_TYPE_LABELS = [
        'fixed' => 'Fixed',
        'hourly' => 'Hourly',
        'quote_on_inspection' => 'Starts From',
    ];

    public function getPriceTypeLabelAttribute(): string
    {
        return self::PRICE_TYPE_LABELS[$this->price_type] ?? $this->price_type;
    }

    /**
     * Same dual-source handling as ServiceCategory::image_url — uploaded
     * files live on the public disk, Glover-imported rows carry full URLs.
     */
    public function getCoverImageUrlAttribute(): ?string
    {
        if (! $this->cover_image) {
            return null;
        }

        if (Str::startsWith($this->cover_image, ['http://', 'https://', '//', 'data:'])) {
            return $this->cover_image;
        }

        return Storage::disk('public')->url($this->cover_image);
    }

    public function category() { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function subcategory() { return $this->belongsTo(ServiceSubcategory::class, 'subcategory_id'); }
    public function optionGroups() { return $this->hasMany(ServiceOptionGroup::class); }
    public function franchisePricing() { return $this->hasMany(FranchiseServicePricing::class); }
    public function bookings() { return $this->hasMany(Booking::class); }

    /**
     * The STORED price cascade for this franchise — franchise
     * `FranchiseServicePricing.price_override` (only when `is_offered`),
     * else `discount_price`, else `base_price`. Extracted from the cascade
     * `Livewire\Bookings\Index` already spelled out by hand, so the admin
     * call-centre form and every other caller can never drift into two
     * different answers. `$franchiseId = null` (no franchise context yet,
     * e.g. catalog browse) skips the override lookup entirely.
     *
     * This is layer ONE of the price a customer actually pays, not the
     * whole of it: an active flash sale wins over the result of this
     * method. The composed answer is FlashSaleService::effectivePriceFor(),
     * which is what both the catalog and CreateBookingAction use. Calling
     * this method alone is correct only where the flash-sale layer is
     * genuinely not wanted (the admin negotiated-price field) or genuinely
     * cannot be expressed (ServiceCatalogQuery's ORDER BY) — both of which
     * say so at the call site.
     */
    public function resolvePrice(?int $franchiseId): float
    {
        $override = $franchiseId
            ? FranchiseServicePricing::where('franchise_id', $franchiseId)
                ->where('service_id', $this->id)
                ->where('is_offered', true)
                ->whereNotNull('price_override')
                ->value('price_override')
            : null;

        return (float) ($override ?? $this->discount_price ?? $this->base_price);
    }

    public function setEffectivePrice(float $price): void
    {
        $this->preresolvedEffectivePrice = $price;
    }

    /**
     * The full cascade's answer for this service: the batched value if one
     * was preloaded by FlashSaleService::effectivePricesFor(), otherwise
     * resolvePrice() alone. A caller that has not preloaded gets the FIRST
     * layer only — which is why ServiceCatalogController preloads rather
     * than leaning on the fallback.
     */
    public function effectivePrice(?int $franchiseId = null): float
    {
        return $this->preresolvedEffectivePrice ?? $this->resolvePrice($franchiseId);
    }
}
