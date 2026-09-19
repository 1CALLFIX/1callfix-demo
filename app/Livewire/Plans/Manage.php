<?php

namespace App\Livewire\Plans;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanEntitlementTarget;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\Setting;
use App\Services\AuthorizationService;
use App\Services\Plans\PlanService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Plans & Memberships" catalog screen — a dedicated top-level admin area,
 * not a Settings tab (approved plan amendment 17: Settings stays global
 * behavioral config only, record management belongs here). Same
 * pinned-add-form-plus-list shape as Categories/Services/Payouts.
 *
 * Membership completion (1CF-MEMBERSHIP-IMPLEMENT-002) extends this SAME screen
 * — no second admin module: a plan can now be edited (price, validity, copy,
 * metadata), an entitlement can be edited, mapped to real catalog categories /
 * services ("eligible targets"), and one that already carries subscriber
 * history can no longer be deleted.
 */
class Manage extends Component
{
    use WithPagination;

    // --- new plan form ---
    public string $name = '';
    public string $description = '';
    /** Raw JSON object of structured plan flags (e.g. {"address_locked": true}). Blank = no metadata. */
    public string $metadataJson = '';
    public string $planFamily = 'customer_membership';
    public ?string $module = 'service';
    public string $scopeType = 'global';
    public ?int $scopeId = null;
    public string $eligibleActorType = 'customer';
    public string $billingCycle = 'monthly';
    public ?int $customCycleDays = null;
    /** Calendar-month validity ("11 months from activation"). Overrides the billing cycle when set. */
    public ?int $validityMonths = null;
    public string $price = '0';
    public string $stackingStrategy = 'exclusive';
    public int $stackingPriority = 0;

    // --- edit an existing plan ---
    public ?int $editingPlanId = null;
    public string $editName = '';
    public string $editDescription = '';
    public string $editMetadataJson = '';
    public string $editBillingCycle = 'monthly';
    public ?int $editCustomCycleDays = null;
    public ?int $editValidityMonths = null;
    public string $editPrice = '0';
    public string $editStackingStrategy = 'exclusive';
    public int $editStackingPriority = 0;

    // --- entitlement form, scoped to one expanded plan ---
    public ?int $expandedPlanId = null;
    public ?int $editingEntitlementId = null;
    public string $entType = 'percentage_discount';
    public ?string $entModule = 'service';
    public ?int $entQuantity = null;
    public ?string $entMonetaryValue = null;
    public ?string $entPercentageValue = null;
    /** Optional display name for one specific entitlement row ("Premium AC Jet Pump Service"). */
    public ?string $entLabel = null;
    /** Comma-separated category choices a redeemer must pick from ("electrical, plumbing, carpenter"). Blank = no choice. */
    public string $entRedeemCategories = '';
    /** '' (legacy discount rule) | service_included | visit_fee_waiver. */
    public string $entEffect = '';
    public string $entDescription = '';
    /** One item per line; a "# name" line starts a group (used for choose-one benefits). */
    public string $entIncludes = '';
    public string $entExcludes = '';

    // --- eligible catalog targets for one entitlement ---
    public ?int $targetingEntitlementId = null;
    public string $tgtType = 'category';
    public ?int $tgtId = null;
    public string $tgtChoice = '';
    public bool $tgtExcluded = false;

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

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashType = $type;
        $this->flashMessage = $message;
    }

    /**
     * Parses a metadata JSON textarea. Returns the decoded array, null for
     * blank, or false (after flashing an error) when it is not a valid JSON
     * object — the caller aborts on false.
     *
     * @return array<string, mixed>|null|false
     */
    private function parseMetadataOrFail(string $json)
    {
        if (trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || array_is_list($decoded)) {
            $this->flash('Metadata must be a valid JSON object, e.g. {"address_locked": true}.', 'error');
            return false;
        }

        return $decoded;
    }

    /**
     * "one item per line" → list; with "# group" header lines → [group => list].
     * Blank = null. Lines before the first header fall under "general".
     *
     * @return array<int|string, mixed>|null
     */
    private function parseScope(string $text): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), fn ($l) => $l !== ''));
        if (! $lines) {
            return null;
        }

        if (! collect($lines)->contains(fn ($l) => str_starts_with($l, '#'))) {
            return $lines;
        }

        $groups = [];
        $current = 'general';
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                $current = strtolower(trim(ltrim($line, '#'))) ?: 'general';
                $groups[$current] ??= [];

                continue;
            }
            $groups[$current][] = $line;
        }

        return array_filter($groups) ?: null;
    }

    private function scopeToText(?array $scope): string
    {
        if (! $scope) {
            return '';
        }
        if (array_is_list($scope)) {
            return implode("\n", $scope);
        }

        $out = [];
        foreach ($scope as $group => $items) {
            $out[] = '# '.$group;
            foreach ($items as $item) {
                $out[] = $item;
            }
        }

        return implode("\n", $out);
    }

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
            $this->flash('You do not have permission to create a plan at this scope.', 'error');
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'metadataJson' => ['nullable', 'string', 'max:5000'],
            'planFamily' => ['required', 'string'],
            'scopeType' => ['required', 'in:global,country,city,zone,franchise'],
            'eligibleActorType' => ['required', 'in:customer,provider,business_account'],
            'billingCycle' => ['required', 'in:daily,weekly,monthly,quarterly,half_yearly,annual,custom'],
            'validityMonths' => ['nullable', 'integer', 'min:1', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            'stackingStrategy' => ['required', 'in:exclusive,stack,highest_benefit_wins,most_specific_wins,priority_order'],
        ]);

        $metadata = $this->parseMetadataOrFail($this->metadataJson);
        if ($metadata === false) {
            return;
        }

        $service->create([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'metadata' => $metadata,
            'plan_family' => $this->planFamily,
            'module' => $this->module ?: null,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeType === 'global' ? null : $this->scopeId,
            'eligible_actor_type' => $this->eligibleActorType,
            'billing_cycle' => $this->billingCycle,
            'custom_cycle_days' => $this->billingCycle === 'custom' ? $this->customCycleDays : null,
            'validity_months' => $this->validityMonths,
            'price' => $this->price,
            'stacking_strategy' => $this->stackingStrategy,
            'stacking_priority' => $this->stackingPriority,
            'is_active' => true,
        ]);

        $this->reset(['name', 'description', 'metadataJson', 'module', 'scopeId', 'customCycleDays', 'validityMonths', 'price', 'stackingPriority']);
        $this->price = '0';
        $this->module = 'service';
        $this->flash('Plan created.');
    }

    public function toggleActive(int $planId): void
    {
        $plan = Plan::findOrFail($planId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flash('You do not have permission to modify this plan.', 'error');
            return;
        }

        app(PlanService::class)->toggleActive($plan);
        $this->flash('Plan '.($plan->fresh()->is_active ? 'activated' : 'deactivated').'.');
    }

    // ------------------------------------------------------------ edit a plan

    public function startEditPlan(int $planId): void
    {
        $plan = Plan::findOrFail($planId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flash('You do not have permission to modify this plan.', 'error');
            return;
        }

        $this->editingPlanId = $plan->id;
        $this->editName = $plan->name;
        $this->editDescription = (string) $plan->description;
        $this->editMetadataJson = $plan->metadata ? json_encode($plan->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $this->editBillingCycle = $plan->billing_cycle;
        $this->editCustomCycleDays = $plan->custom_cycle_days;
        $this->editValidityMonths = $plan->validity_months;
        $this->editPrice = (string) $plan->price;
        $this->editStackingStrategy = $plan->stacking_strategy;
        $this->editStackingPriority = (int) $plan->stacking_priority;
    }

    public function cancelEditPlan(): void
    {
        $this->editingPlanId = null;
    }

    public function updatePlan(PlanService $service): void
    {
        $plan = Plan::findOrFail($this->editingPlanId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flash('You do not have permission to modify this plan.', 'error');
            return;
        }

        $this->validate([
            'editName' => ['required', 'string', 'max:150'],
            'editDescription' => ['nullable', 'string', 'max:5000'],
            'editMetadataJson' => ['nullable', 'string', 'max:20000'],
            'editBillingCycle' => ['required', 'in:daily,weekly,monthly,quarterly,half_yearly,annual,custom'],
            'editValidityMonths' => ['nullable', 'integer', 'min:1', 'max:120'],
            'editPrice' => ['required', 'numeric', 'min:0'],
            'editStackingStrategy' => ['required', 'in:exclusive,stack,highest_benefit_wins,most_specific_wins,priority_order'],
        ]);

        $metadata = $this->parseMetadataOrFail($this->editMetadataJson);
        if ($metadata === false) {
            return;
        }

        try {
            $service->update($plan, [
                'name' => $this->editName,
                'description' => $this->editDescription ?: null,
                'metadata' => $metadata,
                'billing_cycle' => $this->editBillingCycle,
                'custom_cycle_days' => $this->editBillingCycle === 'custom' ? $this->editCustomCycleDays : null,
                'validity_months' => $this->editValidityMonths,
                'price' => $this->editPrice,
                'stacking_strategy' => $this->editStackingStrategy,
                'stacking_priority' => $this->editStackingPriority,
            ]);
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
            return;
        }

        $this->editingPlanId = null;
        $this->flash('Plan updated. Price and validity changes apply to future purchases and renewals; existing periods keep what they were granted.');
    }

    // ------------------------------------------------------------ entitlements

    public function expand(int $planId): void
    {
        $this->expandedPlanId = $this->expandedPlanId === $planId ? null : $planId;
        $this->targetingEntitlementId = null;
        $this->resetEntitlementForm();
    }

    private function resetEntitlementForm(): void
    {
        $this->editingEntitlementId = null;
        $this->reset([
            'entQuantity', 'entMonetaryValue', 'entPercentageValue', 'entLabel', 'entRedeemCategories',
            'entRolloverCap', 'entRolloverExpiryDays', 'entOverageRateValue',
            'entEffect', 'entDescription', 'entIncludes', 'entExcludes',
        ]);
    }

    /** @return array<string, mixed>|null null (after flashing) when the form is invalid */
    private function entitlementPayload(): ?array
    {
        $this->validate([
            'entType' => ['required', 'in:'.implode(',', self::ENTITLEMENT_TYPES)],
            'entEffect' => ['nullable', 'in:,'.implode(',', PlanEntitlement::EFFECTS)],
            'entDescription' => ['nullable', 'string', 'max:2000'],
            'entIncludes' => ['nullable', 'string', 'max:4000'],
            'entExcludes' => ['nullable', 'string', 'max:4000'],
            'entUsagePeriod' => ['required', 'in:per_transaction,daily,monthly,pooled_monthly'],
            'entConsumptionTrigger' => ['required', 'in:booking_created,booking_confirmed,provider_assigned,payment_completed,service_completed,module_specific'],
            'entRolloverPolicy' => ['required', 'in:none,partial,full'],
        ]);

        // A redeemable benefit is consumed once, when the booking is created — the
        // stage the existing architecture consumes at. Any other trigger would leave it inert.
        if ($this->entEffect !== '' && $this->entConsumptionTrigger !== 'booking_created') {
            $this->flash("A redeemable benefit (included service / free visit) must use the 'Booking created' consumption trigger.", 'error');
            return null;
        }

        $redeemCategories = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->entRedeemCategories)
        )));

        return [
            'entitlement_type' => $this->entType,
            'module' => $this->entModule ?: null,
            'label' => $this->entLabel ?: null,
            'redemption_effect' => $this->entEffect !== '' ? $this->entEffect : null,
            'description' => $this->entDescription !== '' ? $this->entDescription : null,
            'includes' => $this->parseScope($this->entIncludes),
            'excludes' => $this->parseScope($this->entExcludes),
            'redeem_categories' => $redeemCategories ?: null,
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
        ];
    }

    public function addEntitlement(PlanService $service): void
    {
        $plan = Plan::findOrFail($this->expandedPlanId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flash('You do not have permission to configure this plan.', 'error');
            return;
        }

        $payload = $this->entitlementPayload();
        if ($payload === null) {
            return;
        }

        $service->addEntitlement($plan, $payload + [
            // commission_override stays unusable (PlanEntitlement::isUsable()) until
            // is_approved is separately flipped via approveOverride() below — this
            // checkbox only records that approval was REQUESTED, never grants it.
            'requires_approval' => $this->entType === 'commission_override' ? true : $this->entRequiresApproval,
            'is_approved' => false,
        ]);

        $this->resetEntitlementForm();
        $this->flash('Entitlement added.');
    }

    public function startEditEntitlement(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::findOrFail($entitlementId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($entitlement->plan))) {
            $this->flash('You do not have permission to configure this plan.', 'error');
            return;
        }

        $this->editingEntitlementId = $entitlement->id;
        $this->entType = $entitlement->entitlement_type;
        $this->entModule = $entitlement->module;
        $this->entLabel = $entitlement->label;
        $this->entRedeemCategories = implode(', ', $entitlement->redeem_categories ?? []);
        $this->entEffect = (string) $entitlement->redemption_effect;
        $this->entDescription = (string) $entitlement->description;
        $this->entIncludes = $this->scopeToText($entitlement->includes);
        $this->entExcludes = $this->scopeToText($entitlement->excludes);
        $this->entQuantity = $entitlement->quantity;
        $this->entMonetaryValue = $entitlement->monetary_value !== null ? (string) $entitlement->monetary_value : null;
        $this->entPercentageValue = $entitlement->percentage_value !== null ? (string) $entitlement->percentage_value : null;
        $this->entUsagePeriod = $entitlement->usage_period;
        $this->entConsumptionTrigger = $entitlement->consumption_trigger;
        $this->entRolloverPolicy = $entitlement->rollover_policy;
        $this->entRolloverCap = $entitlement->rollover_cap;
        $this->entRolloverExpiryDays = $entitlement->rollover_expiry_days;
        $this->entOverageEnabled = (bool) $entitlement->overage_enabled;
        $this->entOverageRateType = $entitlement->overage_rate_type;
        $this->entOverageRateValue = $entitlement->overage_rate_value !== null ? (string) $entitlement->overage_rate_value : null;
    }

    public function cancelEditEntitlement(): void
    {
        $this->resetEntitlementForm();
    }

    /**
     * Saves changes to an existing entitlement. Safe with live subscribers: every
     * purchased period already snapshotted its granted balances, so an edit only
     * shapes FUTURE periods and new subscribers. What the entitlement IS (its
     * type) is frozen once it has history, so past usage stays meaningful.
     */
    public function updateEntitlement(PlanService $service): void
    {
        $entitlement = PlanEntitlement::findOrFail($this->editingEntitlementId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($entitlement->plan))) {
            $this->flash('You do not have permission to configure this plan.', 'error');
            return;
        }

        $payload = $this->entitlementPayload();
        if ($payload === null) {
            return;
        }

        if ($entitlement->hasHistory() && $payload['entitlement_type'] !== $entitlement->entitlement_type) {
            $this->flash('This entitlement already has usage history, so its type cannot be changed.', 'error');
            return;
        }

        $service->updateEntitlement($entitlement, $payload);

        $this->resetEntitlementForm();
        $this->flash('Entitlement updated. Existing subscribers keep the balances they were already granted; the change applies from the next period.');
    }

    public function deleteEntitlement(int $entitlementId, PlanService $service): void
    {
        $entitlement = PlanEntitlement::findOrFail($entitlementId);
        $plan = $entitlement->plan;

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($plan))) {
            $this->flash('You do not have permission to configure this plan.', 'error');
            return;
        }

        try {
            $service->deleteEntitlement($entitlement);
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
            return;
        }

        $this->flash('Entitlement removed.');
    }

    /** commission_override is unusable until an admin explicitly approves it here — separate from configuring it (approved plan §21's "no finalized commercial numbers, no silent go-live"). */
    public function approveOverride(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::findOrFail($entitlementId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($entitlement->plan))) {
            $this->flash('You do not have permission to approve this.', 'error');
            return;
        }

        $entitlement->is_approved = ! $entitlement->is_approved;
        $entitlement->save();
        $this->flash($entitlement->is_approved ? 'Commission override approved.' : 'Commission override approval revoked.');
    }

    // --------------------------------------------- eligible catalog targets

    public function toggleTargets(int $entitlementId): void
    {
        $this->targetingEntitlementId = $this->targetingEntitlementId === $entitlementId ? null : $entitlementId;
        $this->reset(['tgtId', 'tgtChoice', 'tgtExcluded']);
        $this->tgtType = 'category';
    }

    public function addTarget(PlanService $service): void
    {
        $entitlement = PlanEntitlement::findOrFail($this->targetingEntitlementId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($entitlement->plan))) {
            $this->flash('You do not have permission to configure this plan.', 'error');
            return;
        }

        if (! $this->tgtId) {
            $this->flash('Pick a catalog item to map.', 'error');
            return;
        }

        try {
            $service->addTarget($entitlement, $this->tgtType, (int) $this->tgtId, $this->tgtChoice ?: null, $this->tgtExcluded);
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
            return;
        }

        $this->reset(['tgtId', 'tgtChoice', 'tgtExcluded']);
        $this->flash('Catalog target added.');
    }

    public function removeTarget(int $targetId, PlanService $service): void
    {
        $target = PlanEntitlementTarget::findOrFail($targetId);

        if (! auth()->user()->hasPermission('plans.manage', $this->planScopeHint($target->planEntitlement->plan))) {
            $this->flash('You do not have permission to configure this plan.', 'error');
            return;
        }

        $service->removeTarget($target);
        $this->flash('Catalog target removed.');
    }

    /** Options for the target picker, for the chosen type only — the services list is the only large one. */
    private function targetOptions(): array
    {
        if (! $this->targetingEntitlementId) {
            return [];
        }

        return match ($this->tgtType) {
            'category' => ServiceCategory::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'subcategory' => ServiceSubcategory::with('category:id,name')->orderBy('name')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => ($s->category?->name ? $s->category->name.' › ' : '').$s->name])->all(),
            'service' => Service::where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            default => [],
        };
    }

    private function planScopeHint(Plan $plan): array
    {
        return $plan->authorizationScopeHint();
    }

    /**
     * plans.view was seeded but never row-scoped: unlike the geography-column
     * screens, a Plan carries its OWN single (scope_type, scope_id) pair, not
     * separate zone_id/franchise_id/city_id/country_id columns -- the exact
     * shape Plan::authorizationScopeHint() already decomposes for the
     * existing save()/toggleActive()/addEntitlement() mutation checks.
     * AuthorizationService::visibleAmong() reuses that same hint for VIEWING:
     * a lightweight id+scope projection is fetched first (the plan catalog
     * is inherently small -- tens of rows, not thousands), filtered here,
     * then whereIn()'d before the real paginated query runs, so pagination
     * and counts stay correct rather than being computed before this filter.
     */
    private function visiblePlanIds(): array
    {
        $candidates = Plan::query()->select('id', 'scope_type', 'scope_id')->get();

        return app(AuthorizationService::class)
            ->visibleAmong($candidates, auth()->user(), 'plans.view')
            ->pluck('id')
            ->all();
    }

    public function render()
    {
        $plans = Plan::whereIn('id', $this->visiblePlanIds())
            ->with('entitlements.targets')->withCount('subscriptions')->latest()->paginate(15);

        return view('livewire.plans.manage', [
            'plans' => $plans,
            'targetOptions' => $this->targetOptions(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])
            ->layout('layouts.admin', ['title' => 'Plans & Memberships']);
    }
}
