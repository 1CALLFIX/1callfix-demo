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
        // Food F1 (restaurants)
        'fssai_number', 'cuisines', 'cost_for_two', 'packaging_charge', 'prep_minutes', 'busy_until', 'busy_extra_minutes',
    ];

    /** Same as the column default: an admin-created store is approved; RegisterSellerAction sets 'pending'. */
    protected $attributes = ['approval_status' => 'approved'];

    protected $casts = [
        'is_open' => 'boolean',
        'is_active' => 'boolean',
        'prescription_required' => 'boolean',
        'approved_at' => 'datetime',
        'busy_until' => 'datetime',
        'prep_minutes' => 'integer',
        'busy_extra_minutes' => 'integer',
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

    // ── Food F1: restaurants ─────────────────────────────────────────────

    public function isRestaurant(): bool
    {
        return $this->module === \App\Support\Modules::FOOD;
    }

    /** Settings scope of this store (zone → franchise → city → country), for FoodSettings / MarketplaceSettings. */
    public function settingsScope(): array
    {
        return array_filter([
            'zone_id' => $this->zone_id, 'franchise_id' => $this->franchise_id,
            'city_id' => $this->franchise?->city_id, 'country_id' => $this->franchise?->country_id,
        ]);
    }

    /** @return string[] */
    public function cuisineList(): array
    {
        return collect(explode(',', (string) $this->cuisines))->map(fn ($c) => trim($c))->filter()->values()->all();
    }

    /** FSSAI: 14 digits. Required before approval and before taking orders while Food Settings says so. */
    public function missingFssai(): bool
    {
        return $this->isRestaurant()
            && \App\Support\FoodSettings::on('food.fssai_required', $this->settingsScope())
            && ! preg_match('/^\d{14}$/', (string) $this->fssai_number);
    }

    /** The restaurant's local time (franchise timezone). */
    public function localNow(?\Carbon\CarbonInterface $at = null): \Carbon\Carbon
    {
        return \Carbon\Carbon::instance($at ?? now())->setTimezone(app(\App\Services\TimezoneResolver::class)->timezoneFor($this->franchise));
    }

    /**
     * Inside today's opening hours? No hours saved = always (the open/closed switch alone decides, like a shop).
     * A slot whose closing time is earlier than its opening time runs past midnight into the next day.
     */
    public function withinHours(?\Carbon\CarbonInterface $at = null): bool
    {
        $hours = $this->relationLoaded('hours') ? $this->hours : $this->hours()->get();
        if ($hours->isEmpty()) {
            return true;
        }
        $local = $this->localNow($at);
        $time = $local->format('H:i:s');
        $today = $local->dayOfWeek;
        $yesterday = ($today + 6) % 7;

        foreach ($hours as $slot) {
            $opens = $slot->opens_at;
            $closes = $slot->closes_at;
            $overnight = $closes <= $opens;
            if ($slot->day === $today && ($overnight ? $time >= $opens : ($time >= $opens && $time < $closes))) {
                return true;
            }
            if ($overnight && $slot->day === $yesterday && $time < $closes) {
                return true;
            }
        }

        return false;
    }

    /** Taking orders right now: switched on, open, and (a restaurant) inside its hours. */
    public function isOpenNow(?\Carbon\CarbonInterface $at = null): bool
    {
        return $this->is_open && (! $this->isRestaurant() || $this->withinHours($at));
    }

    public function isBusy(?\Carbon\CarbonInterface $at = null): bool
    {
        return $this->busy_until !== null && $this->busy_until->gt($at ?? now()) && (int) $this->busy_extra_minutes > 0;
    }

    /** Cooking time now: the restaurant's own (or the Food Settings default) plus busy minutes. */
    public function currentPrepMinutes(?\Carbon\CarbonInterface $at = null): int
    {
        $base = $this->prep_minutes ?: (int) \App\Support\FoodSettings::get('food.default_prep_minutes', $this->settingsScope());

        return $base + ($this->isBusy($at) ? (int) $this->busy_extra_minutes : 0);
    }

    public function hours() { return $this->hasMany(StoreHour::class)->orderBy('day')->orderBy('opens_at'); }
    public function addOnGroups() { return $this->hasMany(AddOnGroup::class)->orderBy('position')->orderBy('id'); }

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function products() { return $this->hasMany(Product::class); }
    public function addOns() { return $this->hasMany(AddOn::class); }
    public function orders() { return $this->hasMany(MarketplaceOrder::class); }
}
