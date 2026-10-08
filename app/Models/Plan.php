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
        'name', 'description', 'slug', 'plan_family', 'module', 'scope_type', 'scope_id',
        'eligible_actor_type', 'eligibility_rules', 'metadata', 'billing_cycle', 'custom_cycle_days', 'validity_months',
        'price', 'stacking_strategy', 'stacking_priority', 'is_active', 'waives_cancellation_visit_charges',
    ];

    protected $casts = [
        'eligibility_rules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'waives_cancellation_visit_charges' => 'boolean',
        'price' => 'decimal:2',
    ];

    /** In definition order — without an explicit ORDER BY the database returns them by whichever index it picks. */
    public function entitlements() { return $this->hasMany(PlanEntitlement::class)->orderBy('id'); }
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
        // Delegates to AuthorizationService::ancestryFor() -- the exact same
        // franchise/zone ancestry walk this method used to do inline, now
        // shared with NotificationCampaign/NotificationMeeting's identical
        // scope_type/scope_id shape rather than re-implemented per model.
        return app(\App\Services\AuthorizationService::class)->ancestryFor($this->scope_type, $this->scope_id);
    }

    /** Human label for how long one paid period lasts — "11 months", "Monthly", "30 days". */
    public function validityLabel(): string
    {
        if ($this->validity_months) {
            return $this->validity_months.' '.\Illuminate\Support\Str::plural('month', (int) $this->validity_months);
        }

        return match ($this->billing_cycle) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'quarterly' => '3 months',
            'half_yearly' => '6 months',
            'annual' => '12 months',
            'custom' => ($this->custom_cycle_days ?? 30).' days',
            default => ucfirst((string) $this->billing_cycle),
        };
    }

    /** True when the plan's terms tie it to one registered address (metadata.address_locked). */
    public function isAddressLocked(): bool
    {
        return (bool) ($this->metadata['address_locked'] ?? false);
    }

    /** Shared by SubscriptionService (activate/renewNow) and RenewalService (renewPeriod) — one place computes a billing cycle's end. */
    public function computePeriodEnd(\DateTimeInterface $start): \Carbon\Carbon
    {
        $start = \Carbon\Carbon::instance($start);

        // An explicit calendar-month validity ("11 months from activation")
        // wins over the billing_cycle enum, which cannot express "N months".
        if ($this->validity_months) {
            return $start->copy()->addMonthsNoOverflow((int) $this->validity_months);
        }

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
