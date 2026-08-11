<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'plans';

    protected $fillable = [
        'name', 'slug', 'plan_family', 'module', 'scope_type', 'scope_id',
        'eligible_actor_type', 'eligibility_rules', 'billing_cycle', 'custom_cycle_days',
        'price', 'stacking_strategy', 'stacking_priority', 'is_active',
    ];

    protected $casts = [
        'eligibility_rules' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function entitlements() { return $this->hasMany(PlanEntitlement::class); }
    public function subscriptions() { return $this->hasMany(Subscription::class); }

    /**
     * Full ancestor-inclusive scope hint for AuthorizationService::can() —
     * same shape as Bookings\Show::bookingScope() (zone_id/franchise_id/
     * city_id/country_id, only the levels that actually resolve). A
     * country-scoped admin needs country_id present here to cover a
     * franchise- or zone-scoped plan; a single-level hint would silently
     * under-cover that, which is exactly the "lower-scope admin can't touch
     * higher-scope config" boundary this exists to enforce correctly in
     * BOTH directions (higher-scope admins CAN touch narrower plans within
     * their scope; narrower admins can't touch wider ones).
     */
    public function authorizationScopeHint(): array
    {
        if ($this->scope_type === 'global' || ! $this->scope_id) {
            return [];
        }

        return match ($this->scope_type) {
            'franchise' => $this->franchiseAncestry(Franchise::find($this->scope_id)),
            'zone' => $this->zoneAncestry(Zone::find($this->scope_id)),
            'city' => ['city_id' => $this->scope_id],
            'country' => ['country_id' => $this->scope_id],
            default => [],
        };
    }

    private function franchiseAncestry(?Franchise $franchise): array
    {
        if (! $franchise) {
            return [];
        }

        return array_filter([
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ]);
    }

    private function zoneAncestry(?Zone $zone): array
    {
        if (! $zone) {
            return [];
        }

        $franchise = $zone->franchise;

        return array_filter([
            'zone_id' => $zone->id,
            'franchise_id' => $zone->franchise_id,
            'city_id' => $franchise?->city_id,
            'country_id' => $franchise?->country_id,
        ]);
    }

    /** Shared by SubscriptionService (activate/renewNow) and RenewalService (renewPeriod) — one place computes a billing cycle's end. */
    public function computePeriodEnd(\DateTimeInterface $start): \Carbon\Carbon
    {
        $start = \Carbon\Carbon::instance($start);

        return match ($this->billing_cycle) {
            'daily' => $start->copy()->addDay(),
            'weekly' => $start->copy()->addWeek(),
            'monthly' => $start->copy()->addMonthNoOverflow(),
            'quarterly' => $start->copy()->addMonthsNoOverflow(3),
            'half_yearly' => $start->copy()->addMonthsNoOverflow(6),
            'annual' => $start->copy()->addYear(),
            'custom' => $start->copy()->addDays($this->custom_cycle_days ?? 30),
            default => $start->copy()->addMonthNoOverflow(),
        };
    }
}
