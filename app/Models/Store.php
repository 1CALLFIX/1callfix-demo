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
        'address_line', 'lat', 'lng', 'phone', 'gstin', 'pan',
        'is_open', 'is_active', 'prescription_required',
        'approval_status', 'approval_note', 'approved_at', 'approved_by',
        'tax_percent', 'minimum_order_amount', 'delivery_fee_flat', 'free_delivery_above_amount',
    ];

    /** Same as the column default: an admin-created store is approved; RegisterSellerAction sets 'pending'. */
    protected $attributes = ['approval_status' => 'approved'];

    protected $casts = [
        'is_open' => 'boolean',
        'is_active' => 'boolean',
        'prescription_required' => 'boolean',
        'approved_at' => 'datetime',
    ];

    /** E-commerce Phase 1: customers see and buy only from a store that is approved and switched on. */
    public function scopeSellable($query)
    {
        return $query->where('is_active', true)->where('approval_status', 'approved');
    }

    /**
     * City URL architecture (2026-11-01): what a public visitor may see. Sellable, and never a test shop (its seller
     * signs in with an @1callfix.test email, see TestAccountsCommand) or a "[QA] ..." row on production. A signed-in
     * test account still sees test shops, so the walk-through with the test logins keeps working on the live site.
     */
    public function scopePubliclyVisible($query, ?\App\Models\User $viewer = null)
    {
        $query->sellable()->when(\App\Support\Seo\QaRows::hiding(), fn ($q) => $q->where('name', 'not like', \App\Support\Seo\QaRows::LIKE));

        // Owner 2026-11-02: while the shop is being built and tested, test shops show to everyone. Super Admin turns
        // "Hide test shops from customers" ON (Shop Settings) at go-live. Search engines never get them either way.
        if (! \App\Support\MarketplaceSettings::on('marketplace.hide_test_shops')) {
            return $query;
        }

        return self::isTestViewer($viewer ?? auth()->user()) ? $query : $query->notTestShop();
    }

    /** Never a test shop, whoever is looking (search engines, sitemap). */
    public function scopeNotTestShop($query)
    {
        return $query->whereDoesntHave('provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
    }

    public function isTestShop(): bool
    {
        return str_ends_with(strtolower((string) $this->provider?->user?->email), \App\Console\Commands\TestAccountsCommand::DOMAIN);
    }

    public static function isTestViewer(?\App\Models\User $viewer): bool
    {
        return $viewer !== null && str_ends_with(strtolower((string) $viewer->email), \App\Console\Commands\TestAccountsCommand::DOMAIN);
    }

    public function isSellable(): bool
    {
        return $this->is_active && $this->approval_status === 'approved';
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Store $store) {
            // Link rule: a clean, readable slug; -2, -3 only on collision (never a random suffix).
            if (empty($store->slug)) {
                $base = Str::slug($store->name) ?: 'shop';
                $slug = $base;
                for ($n = 2; static::withTrashed()->where('slug', $slug)->exists(); $n++) {
                    $slug = $base.'-'.$n;
                }
                $store->slug = $slug;
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
