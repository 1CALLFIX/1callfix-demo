<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 24 (Marketplace Foundation) — the catalog-owning, franchise/zone-
 * scoped location entity. `provider_id` reuses the existing `Provider`
 * concept as the account/owner tier (see
 * PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md §2) — one Provider can
 * own several Store rows (multi-location).
 */
class Store extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'stores';

    protected $fillable = [
        'provider_id', 'franchise_id', 'zone_id', 'module',
        'name', 'slug', 'description', 'logo', 'cover_image',
        'address_line', 'lat', 'lng', 'phone',
        'is_open', 'is_active', 'prescription_required',
        'tax_percent', 'minimum_order_amount', 'delivery_fee_flat', 'free_delivery_above_amount',
    ];

    protected $casts = [
        'is_open' => 'boolean',
        'is_active' => 'boolean',
        'prescription_required' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Store $store) {
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name).'-'.Str::random(6);
            }
        });
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        if (Str::startsWith($this->logo, ['http://', 'https://', '//', 'data:'])) {
            return $this->logo;
        }

        return Storage::disk('public')->url($this->logo);
    }

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function products() { return $this->hasMany(Product::class); }
    public function addOns() { return $this->hasMany(AddOn::class); }
    public function orders() { return $this->hasMany(MarketplaceOrder::class); }
}
