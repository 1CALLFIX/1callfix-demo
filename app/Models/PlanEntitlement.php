<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanEntitlement extends Model
{
    use HasFactory;

    /** A covered service is included in the membership — its price is waived (capped at monetary_value when set). */
    public const EFFECT_SERVICE_INCLUDED = 'service_included';

    /** Only the service's separable visiting charge is waived; the service itself stays chargeable. */
    public const EFFECT_VISIT_FEE_WAIVER = 'visit_fee_waiver';

    public const EFFECTS = [self::EFFECT_SERVICE_INCLUDED, self::EFFECT_VISIT_FEE_WAIVER];

    protected $table = 'plan_entitlements';

    protected $fillable = [
        'plan_id', 'entitlement_type', 'module', 'label', 'redemption_effect', 'description', 'includes', 'excludes',
        'redeem_categories', 'quantity', 'monetary_value',
        'percentage_value', 'usage_period', 'consumption_trigger', 'rollover_policy',
        'rollover_cap', 'rollover_expiry_days', 'overage_enabled', 'overage_rate_type',
        'overage_rate_value', 'requires_approval', 'is_approved', 'is_enabled',
    ];

    protected $casts = [
        'redeem_categories' => 'array',
        'includes' => 'array',
        'excludes' => 'array',
        'overage_enabled' => 'boolean',
        'requires_approval' => 'boolean',
        'is_approved' => 'boolean',
        'is_enabled' => 'boolean',
        'monetary_value' => 'decimal:2',
        'percentage_value' => 'decimal:2',
        'overage_rate_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Historical usage must never be destroyed by editing a plan: the FKs
        // on entitlement_balances / usage_ledger cascade, so a delete here
        // would silently erase every subscriber's balance and audit trail.
        static::deleting(function (PlanEntitlement $entitlement) {
            if ($entitlement->hasHistory()) {
                throw new \RuntimeException(
                    'This entitlement already has subscriber balances or usage history and cannot be deleted. '
                    .'Edit it instead — changes apply to future periods; past usage is preserved.'
                );
            }
        });
    }

    public function plan() { return $this->belongsTo(Plan::class); }
    public function targets() { return $this->hasMany(PlanEntitlementTarget::class); }

    /** True once any subscriber balance or ledger row references this definition. */
    public function hasHistory(): bool
    {
        return EntitlementBalance::where('plan_entitlement_id', $this->id)->exists()
            || UsageLedger::where('plan_entitlement_id', $this->id)->exists();
    }

    /** Name shown to customers and admins: the label, else the type. */
    public function displayName(): string
    {
        return $this->label ?: ucwords(str_replace('_', ' ', (string) $this->entitlement_type));
    }

    /** A deliberately-redeemed benefit (Prime-style voucher) rather than a legacy price rule. */
    public function isRedemptionBased(): bool
    {
        return in_array($this->redemption_effect, self::EFFECTS, true);
    }

    /**
     * Monetary value granted to a balance for ONE period. For a redeemable
     * quantity benefit `monetary_value` is the advertised value PER UNIT
     * (₹1,500 each), so the period's grant is quantity × that. Every other
     * entitlement keeps its existing meaning (the pool itself).
     */
    public function grantedMonetaryValue(): float
    {
        $value = (float) ($this->monetary_value ?? 0);

        if ($this->isRedemptionBased() && $this->quantity !== null) {
            return round($value * (int) $this->quantity, 2);
        }

        return $value;
    }

    /** True when a redemption of this entitlement must name one category from a fixed set (Prime Silver's Home Service Credit). */
    public function requiresCategoryChoice(): bool
    {
        return is_array($this->redeem_categories) && count($this->redeem_categories) > 0;
    }

    /**
     * Does this entitlement cover $service, judged against the EXISTING
     * catalog (service → subcategory → category)?
     *
     * Returns null when it does not cover it, otherwise
     * ['choice' => <redeem_categories key or null>].
     *
     *  - An exclusion target that matches always wins (a carve-out such as
     *    "AC is covered except Gas Charging").
     *  - With no inclusion targets: a legacy entitlement (no redemption
     *    effect) covers everything, exactly as before; a visit-fee waiver
     *    covers any service (it still needs a visiting charge to waive); a
     *    `service_included` entitlement covers NOTHING — an unmapped included
     *    service must never silently give away every service.
     *  - A choose-one entitlement resolves to the choice_key of the matching
     *    inclusion target, which is what gets recorded on the ledger. A target
     *    whose choice_key is not one of redeem_categories is a misconfiguration
     *    and is treated as not covering.
     */
    public function coversService(Service $service, ?string $requestedChoice = null): ?array
    {
        $targets = $this->relationLoaded('targets') ? $this->targets : $this->targets()->get();

        foreach ($targets->where('is_excluded', true) as $exclusion) {
            if ($exclusion->matches($service)) {
                return null;
            }
        }

        $inclusions = $targets->where('is_excluded', false);

        if ($inclusions->isEmpty()) {
            return $this->redemption_effect === self::EFFECT_SERVICE_INCLUDED
                ? null
                : ['choice' => null];
        }

        foreach ($inclusions as $target) {
            if (! $target->matches($service)) {
                continue;
            }

            if ($this->requiresCategoryChoice()) {
                if (! in_array($target->choice_key, $this->redeem_categories, true)) {
                    continue;
                }
                if ($requestedChoice !== null && $requestedChoice !== $target->choice_key) {
                    continue;
                }

                return ['choice' => $target->choice_key];
            }

            return ['choice' => null];
        }

        return null;
    }

    /**
     * commission_override is unusable unless BOTH requires_approval was
     * turned on for this entitlement AND an admin has actually approved it —
     * amendment 21's "no finalized commercial numbers, no silent go-live for
     * a rate override" concern, enforced here rather than trusted to the
     * admin form alone.
     */
    public function isUsable(): bool
    {
        // Switched off by an admin: never granted, redeemed or shown.
        if ($this->is_enabled === false) {
            return false;
        }

        if ($this->entitlement_type === 'commission_override') {
            return $this->requires_approval && $this->is_approved;
        }

        return true;
    }
}
