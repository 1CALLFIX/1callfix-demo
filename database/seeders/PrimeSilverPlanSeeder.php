<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Configures the "1CallFix Prime Silver — Home Protection Plan" against the
 * existing Plan Engine (plans / plan_entitlements). The numbers and terms
 * come verbatim from the business's printed membership card — this seeder
 * only stores them, it does not invent pricing or benefits.
 *
 * Idempotent: keyed on slug '1callfix-prime-silver', re-running replaces
 * the plan's own columns and rebuilds its entitlement rows. It refuses to
 * touch a plan that already has live subscriptions (guards against a
 * re-run silently changing what existing members are entitled to) —
 * reports and skips instead.
 *
 * Structural rule, not a per-entitlement note: spare parts and materials
 * are always separately chargeable on every use of every entitlement.
 * That is recorded once in metadata.spare_parts_chargeable and in the
 * description, never repeated five times.
 */
class PrimeSilverPlanSeeder extends Seeder
{
    private const SLUG = '1callfix-prime-silver';

    public function run(): void
    {
        $existing = Plan::withTrashed()->where('slug', self::SLUG)->first();

        if ($existing && $existing->subscriptions()->exists()) {
            $this->command?->warn(
                "Plan '".self::SLUG."' already has subscriptions — not re-seeding. ".
                'Edit it through the Plans & Memberships admin screen instead.'
            );

            return;
        }

        DB::transaction(function () use ($existing) {
            $plan = Plan::withTrashed()->updateOrCreate(
                ['slug' => self::SLUG],
                [
                    'name' => '1CallFix Prime Silver — Home Protection Plan',
                    'description' => $this->description(),
                    'metadata' => $this->metadata(),
                    'plan_family' => 'customer_membership',
                    'module' => 'service',
                    'scope_type' => 'global',
                    'scope_id' => null,
                    'eligible_actor_type' => 'customer',
                    'billing_cycle' => 'custom',
                    'custom_cycle_days' => 334, // 11 months from activation
                    'price' => 1999.00,         // ₹1,999/year founding member offer
                    'stacking_strategy' => 'exclusive',
                    'stacking_priority' => 0,
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );

            // Rebuild entitlements from scratch (safe: guarded against
            // subscriptions above, so no live balance references these).
            PlanEntitlement::where('plan_id', $plan->id)->delete();

            foreach ($this->entitlements() as $entitlement) {
                PlanEntitlement::create(array_merge([
                    'plan_id' => $plan->id,
                    'module' => 'service',
                    'usage_period' => 'monthly',
                    // Consumed via the explicit RedeemEntitlementAction, not
                    // the automatic booking-time pricing resolver — but a
                    // trigger value is required, and service_completed is the
                    // honest one (the visit has to have happened).
                    'consumption_trigger' => 'service_completed',
                    'rollover_policy' => 'none', // reset to zero, no carryover
                ], $entitlement));
            }

            $this->command?->info(
                ($existing ? 'Updated' : 'Created')." plan #{$plan->id} '{$plan->name}' with ".
                count($this->entitlements()).' entitlements.'
            );
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function entitlements(): array
    {
        return [
            [
                'entitlement_type' => 'quantity',
                'label' => 'Premium AC Jet Pump Service',
                'quantity' => 2,
                // Included: jet pump cleaning, indoor/outdoor unit cleaning,
                // filter cleaning, drain line cleaning, performance check,
                // basic inspection. Excluded (chargeable): gas charging, gas
                // leak rectification, compressor repair, PCB repair, install/
                // uninstall/shifting, drain pipe replacement, spare parts,
                // copper pipe replacement.
            ],
            [
                'entitlement_type' => 'quantity',
                'label' => 'Appliance General Service',
                'quantity' => 1,
                // Included: geyser repair minor service, purifier service
                // excluding filters, appliance inspection, basic
                // troubleshooting. Excluded: full servicing, water
                // replacement, PCB repairs, compressor repairs, major
                // internal repairs.
            ],
            [
                'entitlement_type' => 'quantity',
                'label' => 'Home Service Credit',
                'quantity' => 1,
                // Category-agnostic until redeemed: the customer picks ONE of
                // these per use, recorded on usage_ledger.redeemed_category.
                'redeem_categories' => ['electrical', 'plumbing', 'carpenter'],
            ],
            [
                'entitlement_type' => 'fee_waiver',
                'label' => 'Free Service Visit (waives visit/inspection fee only)',
                'quantity' => 5,
                // Waives the minimum viewing/call-out charge. The work itself
                // is still priced normally.
            ],
            [
                'entitlement_type' => 'priority',
                'label' => 'Priority-based service (allocation preference; no immediate-service guarantee)',
                'quantity' => null,
                // Recorded as a plan fact. Nothing in dispatch reads it yet;
                // the plan's own terms state it does not guarantee immediate
                // service.
            ],
        ];
    }

    private function description(): string
    {
        return implode(' ', [
            'Founding member offer at ₹1,999/year.',
            'Validity: 11 months from the activation date.',
            'Membership is valid for the registered address only and is not transferable to another address.',
            'All benefits reset to zero once used; unused benefits cannot be carried forward or transferred.',
            'Priority-based service means preference in technician allocation only — it does NOT guarantee immediate service.',
            'Service availability is subject to technician availability in the zone.',
            'The platform reserves the right to inspect and determine service eligibility.',
            'The plan does not cover installation, uninstallation, major repairs, or replacements under any entitlement.',
            'Spare parts and materials are always separately chargeable across every entitlement, on every use.',
        ]);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'offer' => 'founding_member',
            'validity_months' => 11,
            'validity_from' => 'activation_date',
            'address_locked' => true,
            'transferable' => false,
            'carry_forward' => false,
            'priority_guarantees_immediate_service' => false,
            'spare_parts_chargeable' => true,
            'eligibility_inspection_reserved' => true,
            'zone_availability_subject_to_technician' => true,
            'excludes' => ['installation', 'uninstallation', 'major_repairs', 'replacements'],
        ];
    }
}
