<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Banner extends Model
{
    use HasFactory;

    protected $table = 'banners';

    /**
     * The sellable ad slots. `top` is the home-screen hero carousel (premium
     * rate); `mid` is the strip between modules, about half-way down the
     * scroll (standard rate). Adding a slot here is all that's needed to
     * offer it — the admin screen builds its dropdowns and filters from this.
     */
    public const PLACEMENTS = [
        'top' => 'Top — home hero carousel',
        'mid' => 'Mid — between modules',
    ];

    /**
     * The targeting axes, in order of how narrow they are. Null on any of
     * these means "not restricted by this" — a banner with all four null runs
     * everywhere. Kept as one list so forSlot() and the specificity ranking
     * can never drift out of sync with each other.
     */
    public const TARGET_AXES = ['franchise_id', 'zone_id', 'module', 'category_id'];

    protected $fillable = [
        'franchise_id',
        'zone_id',
        'module',
        'placement',
        'category_id',
        'title',
        'image',
        'link',
        'starts_at',
        'expires_at',
        'advertiser_name',
        'advertiser_contact',
        'price_paid',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function category() { return $this->belongsTo(ServiceCategory::class, 'category_id'); }

    /** Banners for one on-screen slot, e.g. Banner::placement('top'). */
    public function scopePlacement($query, string $placement)
    {
        return $query->where('placement', $placement);
    }

    /**
     * THE resolver: which banners fill a given slot for a given viewer.
     *
     * Every consumer — customer app API, website home screen, admin preview —
     * must go through this rather than hand-rolling its own where() chain.
     * That's the whole point: targeting rules live in exactly one place, so
     * adding a fifth axis (or swapping to a pivot table later) is a change
     * here and nowhere else. Three near-identical copies quietly disagreeing
     * is the failure mode this exists to prevent.
     *
     * Null on a banner's axis = wildcard, so an untargeted banner shows to
     * everyone. Null in $context = "the viewer isn't in that scope", which
     * only matches banners that don't restrict on it.
     *
     * Results are ordered most-specific-first (a banner pinned to this exact
     * category beats a franchise-wide one, which beats a global one), then by
     * the admin's manual sort_order. Without that, a global house banner
     * could outrank a slot someone actually paid to target.
     *
     * @param  array{franchise_id?:int|null, zone_id?:int|null, module?:string|null, category_id?:int|null}  $context
     */
    public function scopeForSlot($query, string $placement, array $context = [])
    {
        $query->placement($placement)->currentlyLive();

        foreach (self::TARGET_AXES as $axis) {
            $value = $context[$axis] ?? null;

            $query->where(function ($q) use ($axis, $value) {
                $q->whereNull($axis);

                if ($value !== null) {
                    $q->orWhere($axis, $value);
                }
            });
        }

        // Boolean arithmetic: each non-null axis adds 1, so the banner
        // restricting on the most axes sorts first.
        $specificity = collect(self::TARGET_AXES)
            ->map(fn ($axis) => "($axis is not null)")
            ->implode(' + ');

        return $query->orderByRaw("({$specificity}) desc")->orderBy('sort_order')->orderBy('id');
    }

    /** How many axes this banner restricts on — 0 means it runs everywhere. */
    public function getSpecificityAttribute(): int
    {
        return collect(self::TARGET_AXES)
            ->filter(fn ($axis) => $this->{$axis} !== null)
            ->count();
    }

    /**
     * Uploaded banners hold a path on the `public` disk; anything seeded or
     * imported may hold a full URL. Resolve both — same contract as
     * ServiceCategory::image_url.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (Str::startsWith($this->image, ['http://', 'https://', '//', 'data:'])) {
            return $this->image;
        }

        return Storage::disk('public')->url($this->image);
    }

    /**
     * Where a banner sits in its lifecycle, for the admin list badge:
     * inactive (switched off) > scheduled (window hasn't opened) >
     * expired (window closed) > live. Deliberately mirrors scopeCurrentlyLive
     * so the badge and the "live" filter can never disagree.
     */
    public function getStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'live';
    }

    /** True for a sold ad slot, false for a house/own-promo banner. */
    public function getIsPaidAttribute(): bool
    {
        return $this->price_paid !== null;
    }

    /**
     * Human summary of WHO sees this banner — the geography half (zone beats
     * franchise beats everywhere), optionally narrowed to one category.
     *
     * Named `targeting`, not `placement`: `placement` is the column holding
     * which on-screen slot the banner occupies (top vs mid), which is a
     * different question from who it's shown to.
     */
    public function getTargetingAttribute(): string
    {
        $parts = [
            match (true) {
                (bool) $this->zone => $this->zone->display_name,
                (bool) $this->franchise => $this->franchise->name,
                default => 'All franchises',
            },
        ];

        // Module is implied by a category, so only worth showing on its own.
        if ($this->module && ! $this->category) {
            $parts[] = \App\Support\Modules::label($this->module);
        }

        if ($this->category) {
            $parts[] = $this->category->name;
        }

        return implode(' · ', $parts);
    }

    /** Which on-screen slot this occupies, in words. */
    public function getPlacementLabelAttribute(): string
    {
        return self::PLACEMENTS[$this->placement] ?? $this->placement;
    }

    /** Short form for narrow table cells. */
    public function getPlacementShortAttribute(): string
    {
        return $this->placement === 'top' ? 'Top' : 'Mid';
    }

    /**
     * Banners that should actually be shown right now: active flag on,
     * and (if dates are set) within the paid window. Banners with no
     * starts_at/expires_at are treated as always-on (house banners).
     */
    public function scopeCurrentlyLive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
    }

    /**
     * Paid ad slots expiring within the next N days — useful for a renewal
     * reminder list so revenue doesn't quietly lapse unnoticed.
     */
    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->whereNotNull('price_paid')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }
}
