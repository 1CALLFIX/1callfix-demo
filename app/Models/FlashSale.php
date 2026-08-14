<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlashSale extends Model
{
    use HasFactory;

    protected $table = 'flash_sales';

    protected $fillable = [
        'name', 'customer_title', 'description', 'banner_image', 'type', 'status',
        'starts_at', 'ends_at', 'timezone', 'scope_type', 'scope_id',
        'discount_type', 'discount_value', 'max_discount', 'min_final_price',
        'total_quantity_limit', 'per_customer_limit', 'badge_id', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'discount_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_final_price' => 'decimal:2',
    ];

    public const TERMINAL_STATUSES = ['completed', 'cancelled'];

    public function targets() { return $this->hasMany(FlashSaleTarget::class); }
    public function services() { return $this->belongsToMany(Service::class, 'flash_sale_targets'); }
    public function redemptions() { return $this->hasMany(FlashSaleRedemption::class); }
    public function badge() { return $this->belongsTo(Badge::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** Ancestor-inclusive scope hint for AuthorizationService::can()/scopeQuery()/scopeCovers() — same pattern as Plan/Badge. */
    public function authorizationScopeHint(): array
    {
        return app(\App\Services\AuthorizationService::class)->ancestryFor($this->scope_type, $this->scope_id);
    }

    /**
     * The ONE source of truth for "does this sale's discount apply right
     * now" — server-time authoritative, never trusting `status` alone
     * (the mission's own explicit instruction). 'paused' is a pure admin
     * override that suppresses the discount even mid-window; 'draft',
     * 'completed', and 'cancelled' never apply regardless of the time
     * window; 'scheduled' and 'live' both require now() to actually be
     * inside [starts_at, ends_at) — a 'scheduled' sale whose starts_at has
     * already passed behaves as live immediately, with no sync command
     * required to flip the status column first.
     */
    public function isCurrentlyActive(): bool
    {
        if (! in_array($this->status, ['scheduled', 'live'], true)) {
            return false;
        }

        if (! $this->starts_at || ! $this->ends_at) {
            return false;
        }

        $now = now();

        return $now->greaterThanOrEqualTo($this->starts_at) && $now->lessThan($this->ends_at);
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query->whereIn('status', ['scheduled', 'live'])
            ->whereNotNull('starts_at')->whereNotNull('ends_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now);
    }

    /**
     * The discounted price for a given original price — floored at both 0
     * and min_final_price (whichever is higher), and at max_discount for a
     * percent-type sale. Never returns a negative or zero-when-unintended
     * price; callers (FlashSaleService::priceFor()) only call this after
     * confirming isCurrentlyActive().
     */
    public function computeFinalPrice(float $originalPrice): float
    {
        $discount = $this->discount_type === 'percent'
            ? $originalPrice * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        $final = round($originalPrice - $discount, 2);

        return max($final, (float) $this->min_final_price, 0.0);
    }
}
