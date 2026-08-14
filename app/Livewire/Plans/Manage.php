<?php

namespace App\Livewire\Plans;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Services\Plans\PlanService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Plans & Memberships" catalog screen — a dedicated top-level admin area,
 * not a Settings tab (approved plan amendment 17: Settings stays global
 * behavioral config only, record management belongs here). Same
 * pinned-add-form-plus-list shape as Categories/Services/Payouts.
 */
class Manage extends Component
{
    use WithPagination;

    // --- new plan form ---
    public string $name = '';
    public string $planFamily = 'customer_membership';
    public ?string $module = 'service';
    public string $scopeType = 'global';
    public ?int $scopeId = null;
    public string $eligibleActorType = 'customer';
    public string $billingCycle = 'monthly';
    public ?int $customCycleDays = null;
    public string $price = '0';
    public string $stackingStrategy = 'exclusive';
    public int $stackingPriority = 0;

    // --- entitlement form, scoped to one expanded plan ---
    public ?int $expandedPlanId = null;
    public string $entType = 'percentage_discount';
    public ?string $entModule = 'service';
    public ?int $entQuantity = null;
    public ?string $entMonetaryValue = null;
    public ?string $entPercentageValue = null;

    /** plans.view was seeded (2026_08_11_038000) but never checked on this screen (only the mutating actions check plans.manage) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('plans.view'), 403, 'You do not have permission to view plans.');
    }
    public string $entUsagePeriod = 'monthly';
    public string $entConsumptionTrigger = 'booking_created';
    public string $entRolloverPolicy = 'none';
    public ?int $entRolloverCap = null;
    public ?int $entRolloverExpiryDays = null;
    public bool $entOverageEnabled = false;
    public ?string $entOverageRateType = null;
    public ?string $entOverageRateValue = null;
    public bool $entRequiresApproval = false;

    public string $flashMessage = '';
    public string $flashType = 'success';

    public const PLAN_FAMILIES = ['customer_membership', 'provider_package'];
    public const ENTITLEMENT_TYPES = [
        'quantity', 'monetary_allowance', 'percentage_discount', 'fixed_discount',
        'fee_waiver', 'member_price', 'commission_reduction', 'commission_override',
        'priority', 'feature_access',
    ];

    private function scopeHint(): array
    {
        // Not-yet-persisted Plan -- authorizationScopeHint() only reads
        // scope_type/scope_id (to look up the Franchise/Zone ancestry), so
        // an in-memory instance works exactly like a saved one here.
        return (new Plan(['scope_type' => $this->scopeType, 'scope_id' => $this->scopeId]))->authorizationScopeHint();
    }

    public function save(PlanService $service): void
    {
        if (! auth()->user()->hasPermission('plans.manage', $this->scopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to create a plan at this scope.';
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'planFamily' => ['required', 'string'],
            'scopeType' => ['required', 'in:global,country,city,zone,franchise'],
            'eligibleActorType' => ['required', 'in:customer,provider,business_account'],
            'billingCycle' => ['required', 'in:daily,weekly,monthly,quarterly,half_yearly,annual,custom'],
            'price' => ['required', 'numeric', 'min:0'],
            'stackingStrategy' => ['required', 'in:exclusive,stack,highest_benefit_wins,most_specific_wins,priority_order'],
        ]);

        $service->create([
            'name' => $this->name,
            'plan_family' => $this->planFamily,
            'module' => $this->module ?: null,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeType === 'global' ? null : $this->scopeId,
            'eligible_actor_type' => $this->eligibleActorType,
            'billing_cycle' => $this->billingCycle,
            'custom_cycle_days' => $this->billingCycle === 'custom' ? $this->customCycleDays : null,
            'price' => $this->price,
            'stacking_strategy' => $this->stackingStrategy,
            'stacking_priority' => $this->stackingPriority,
            'is_active' => true,
        ]);

        $this->reset(['name', 'module', 'scopeId', 'customCycleDays', 'price', 'stackingPriority']);
        $this->price = '0';
        $this->module = 'service';
        $this->flashType = 'success';
        $this->flashMessage = 'Plan created.';
    }

    public function toggleActive(int $planId): void
    {
        $plan = Plan::findOrFail($planId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to modify this plan.';
            return;
        }

        app(PlanService::class)->toggleActive($plan);
        $this->flashType = 'success';
        $this->flashMessage = 'Plan '.($plan->fresh()->is_active ? 'activated' : 'deactivated').'.';
    }

    public function expand(int $planId): void
    {
        $this->expandedPlanId = $this->expandedPlanId === $planId ? null : $planId;
        $this->reset(['entQuantity', 'entMonetaryValue', 'entPercentageValue', 'entRolloverCap', 'entRolloverExpiryDays', 'entOverageRateValue']);
    }

    public function addEntitlement(PlanService $service): void
    {
        $plan = Plan::findOrFail($this->expandedPlanId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to configure this plan.';
            return;
        }

        $this->validate([
            'entType' => ['required', 'in:'.implode(',', self::ENTITLEMENT_TYPES)],
            'entUsagePeriod' => ['required', 'in:per_transaction,daily,monthly,pooled_monthly'],
            'entConsumptionTrigger' => ['required', 'in:booking_created,booking_confirmed,provider_assigned,payment_completed,service_completed,module_specific'],
            'entRolloverPolicy' => ['required', 'in:none,partial,full'],
        ]);

        $service->addEntitlement($plan, [
            'entitlement_type' => $this->entType,
            'module' => $this->entModule ?: null,
            'quantity' => $this->entQuantity,
            'monetary_value' => $this->entMonetaryValue,
            'percentage_value' => $this->entPercentageValue,
            'usage_period' => $this->entUsagePeriod,
            'consumption_trigger' => $this->entConsumptionTrigger,
            'rollover_policy' => $this->entRolloverPolicy,
            'rollover_cap' => $this->entRolloverCap,
            'rollover_expiry_days' => $this->entRolloverExpiryDays,
            'overage_enabled' => $this->entOverageEnabled,
            'overage_rate_type' => $this->entOverageEnabled ? $this->entOverageRateType : null,
            'overage_rate_value' => $this->entOverageEnabled ? $this->entOverageRateValue : null,
            // commission_override stays unusable (PlanEntitlement::isUsable()) until
            // is_approved is separately flipped via approveOverride() below — this
            // checkbox only records that approval was REQUESTED, never grants it.
            'requires_approval' => $this->entType === 'commission_override' ? true : $this->entRequiresApproval,
            'is_approved' => false,
        ]);

        $this->reset(['entQuantity', 'entMonetaryValue', 'entPercentageValue', 'entRolloverCap', 'entRolloverExpiryDays', 'entOverageRateValue']);
        $this->flashType = 'success';
        $this->flashMessage = 'Entitlement added.';
    }

    public function deleteEntitlement(int $entitlementId, PlanService $service): void
    {
        $entitlement = PlanEntitlement::findOrFail($entitlementId);
        $plan = $entitlement->plan;

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to configure this plan.';
            return;
        }

        $service->deleteEntitlement($entitlement);
        $this->flashType = 'success';
        $this->flashMessage = 'Entitlement removed.';
    }

    /** commission_override is unusable until an admin explicitly approves it here — separate from configuring it (approved plan §21's "no finalized commercial numbers, no silent go-live"). */
    public function approveOverride(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::findOrFail($entitlementId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($entitlement->plan))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to approve this.';
            return;
        }

        $entitlement->is_approved = ! $entitlement->is_approved;
        $entitlement->save();
        $this->flashType = 'success';
        $this->flashMessage = $entitlement->is_approved ? 'Commission override approved.' : 'Commission override approval revoked.';
    }

    private function planScopeHint(Plan $plan): array
    {
        return $plan->authorizationScopeHint();
    }

    public function render()
    {
        $plans = Plan::with('entitlements')->withCount('subscriptions')->latest()->paginate(15);

        return view('livewire.plans.manage', ['plans' => $plans])
            ->layout('layouts.admin', ['title' => 'Plans & Memberships']);
    }
}
