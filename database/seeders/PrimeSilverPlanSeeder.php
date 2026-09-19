<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Configures the "1CallFix Prime Silver — Home Protection Plan" against the
 * existing Plan Engine (plans / plan_entitlements). The numbers and terms
 * come verbatim from the business's approved printed membership card — this
 * seeder only stores them, it does not invent pricing or benefits.
 *
 * Idempotent and SAFE ON A LIVE DATABASE: keyed on slug '1callfix-prime-silver',
 * and each entitlement is matched BY LABEL and updated in place — never deleted
 * and re-created — so entitlement ids, and any catalog targets an admin has
 * already mapped in /admin/plans, survive a re-run. It refuses to touch a plan
 * that already has live subscriptions (guards against a re-run silently
 * changing what existing members are entitled to) — reports and skips instead.
 *
 * It does NOT guess which catalog services a benefit covers. Mapping each
 * entitlement to real categories / services is a catalog decision made in
 * /admin/plans (Entitlements → Eligible catalog targets). Until an included-
 * service entitlement is mapped it is inert by design — it can never give away
 * every service.
 *
 * Structural rule: spare parts, materials and out-of-scope work are always
 * separately chargeable on every use of every entitlement. That is recorded
 * once in metadata and in the terms, never repeated per entitlement.
 */
class PrimeSilverPlanSeeder extends Seeder
{
    public const SLUG = '1callfix-prime-silver';

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
                    'custom_cycle_days' => 334, // fallback only; validity_months below is authoritative
                    'validity_months' => 11,    // 11 months from the activation date
                    'price' => 1999.00,         // ₹1,999/year founding member offer
                    'stacking_strategy' => 'exclusive',
                    'stacking_priority' => 0,
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );

            $labels = [];
            foreach ($this->entitlements() as $spec) {
                $labels[] = $spec['label'];

                PlanEntitlement::updateOrCreate(
                    ['plan_id' => $plan->id, 'label' => $spec['label']],
                    array_merge([
                        'module' => 'service',
                        'usage_period' => 'monthly',
                        // Redeemed at booking creation — the stage the existing
                        // entitlement architecture already consumes at.
                        'consumption_trigger' => 'booking_created',
                        'rollover_policy' => 'none', // reset to zero, no carry-over
                        'redemption_effect' => null,
                        'monetary_value' => null,
                        'redeem_categories' => null,
                        'quantity' => null,
                    ], $spec)
                );
            }

            // Drop only rows that are no longer part of the card. Model-level
            // delete on purpose: PlanEntitlement refuses if it has history.
            PlanEntitlement::where('plan_id', $plan->id)
                ->whereNotIn('label', $labels)
                ->get()
                ->each->delete();

            $entitlements = $plan->entitlements()->withCount('targets')->get();
            $unmapped = $entitlements->filter(fn ($e) => $e->redemption_effect === PlanEntitlement::EFFECT_SERVICE_INCLUDED && $e->targets_count === 0);

            $this->command?->info(
                ($existing ? 'Updated' : 'Created')." plan #{$plan->id} '{$plan->name}' with {$entitlements->count()} entitlements."
            );

            if ($unmapped->isNotEmpty()) {
                $this->command?->warn(
                    $unmapped->count().' included-service benefit(s) have no catalog services mapped yet and will not apply: '
                    .$unmapped->pluck('label')->implode('; ').'. Map them in /admin/plans → Entitlements.'
                );
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function entitlements(): array
    {
        return [
            [
                'label' => 'Premium AC Jet Pump Service',
                'entitlement_type' => 'quantity',
                'quantity' => 2,
                'monetary_value' => 1500.00, // advertised value PER service (₹1,500 each, ₹3,000 total)
                'redemption_effect' => PlanEntitlement::EFFECT_SERVICE_INCLUDED,
                'description' => 'Two Premium AC Jet Pump Services. Advertised value ₹1,500 each (₹3,000 in total).',
                'includes' => [
                    'Jet Pump Cleaning',
                    'Indoor Unit Cleaning',
                    'Outdoor Unit Cleaning',
                    'Filter Cleaning',
                    'Drain Line Cleaning',
                    'Performance Check',
                    'Basic Inspection',
                ],
                'excludes' => [
                    'Gas Charging',
                    'Gas Leak Rectification',
                    'Compressor Repair',
                    'PCB Repair',
                    'Refrigerant Refill',
                    'AC Installation',
                    'AC Shifting',
                    'Drain Pipe Replacement',
                    'Spare Parts Replacement',
                    'Copper Pipe Replacement',
                ],
            ],
            [
                'label' => 'Appliance General Service',
                'entitlement_type' => 'quantity',
                'quantity' => 1,
                'redemption_effect' => PlanEntitlement::EFFECT_SERVICE_INCLUDED,
                'description' => 'One Appliance General Service. This is a separate benefit — it is not one of the Home Service Credit choices.',
                'includes' => [
                    'Geyser Minor Service',
                    'Minor Service for Home Appliances',
                    'Appliance Inspection',
                    'Basic Troubleshooting',
                ],
                'excludes' => [
                    'Refrigerant / Gas Charging',
                    'Spare Parts Replacement',
                    'Motor Replacement',
                    'PCB Repairs',
                    'Compressor Repairs',
                    'Major Internal Repairs',
                ],
            ],
            [
                'label' => 'Home Service Credit',
                'entitlement_type' => 'quantity',
                'quantity' => 1,
                // Choose ONE. Appliance is deliberately NOT a choice — it has its own entitlement.
                'redeem_categories' => ['electrical', 'plumbing', 'carpenter'],
                'redemption_effect' => PlanEntitlement::EFFECT_SERVICE_INCLUDED,
                'description' => 'One Home Service Credit, usable for ONE of Electrical, Plumbing or Carpenter General Service. Once used it cannot be used for another category.',
                'includes' => [
                    'electrical' => [
                        'Minor Electrical Inspection',
                        'MCB Replacement',
                        'Fan Installation',
                        'Loose Connection Rectification',
                        'Switch & Socket Checking',
                    ],
                    'plumbing' => [
                        'Tap Replacement',
                        'Kitchen Sink Pipe',
                        'WC Coupling',
                        'Minor Leak Inspection',
                    ],
                    'carpenter' => [
                        'Door Adjustment',
                        'Hinges / Lock Replacement',
                        'Drawer Repair',
                        'Wooden Panel Fixing',
                        'Minor Woodwork Repair',
                    ],
                ],
                'excludes' => [
                    'electrical' => [
                        'Concrete House Wiring',
                        'Rewiring Works',
                        'New Electrical Installation',
                        'Distribution Board Replacement',
                        'Spare Parts & Materials',
                    ],
                    'plumbing' => [
                        'Major Leakage Repair',
                        'Drainage Block Repair',
                        'Underground Pipeline Works',
                        'Bathroom Renovation Works',
                        'Water Tank Cleaning',
                        'Spare Parts & Materials',
                    ],
                    'carpenter' => [
                        'Furniture Manufacturing',
                        'Full Door/Window Replacement',
                        'Polish / Paint Works',
                        'Plywood / Board Replacement',
                        'Spare Parts & Materials',
                    ],
                ],
            ],
            [
                'label' => 'Free Service Visit (waives visit/inspection fee only)',
                'entitlement_type' => 'fee_waiver',
                'quantity' => 5,
                // Waives ONLY the visiting / service-call charge (services.visiting_charge).
                // It never zeroes a booking: the work itself is still priced normally.
                'redemption_effect' => PlanEntitlement::EFFECT_VISIT_FEE_WAIVER,
                'description' => 'Five free service visits. Each waives the visiting / service-call charge only, for an eligible service call. The service itself, spare parts, materials and out-of-scope work remain chargeable.',
            ],
            [
                'label' => 'Priority-based service (allocation preference; no immediate-service guarantee)',
                'entitlement_type' => 'priority',
                'quantity' => null,
                'description' => 'Priority Based Service — preference in technician allocation. It does NOT guarantee immediate service; availability depends on technician availability.',
            ],
        ];
    }

    private function description(): string
    {
        return implode(' ', [
            'Founding member offer at ₹1,999.',
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
            'materials_chargeable' => true,
            'additional_visits_chargeable' => true,
            'out_of_scope_chargeable' => true,
            'free_visits_limit' => 5,
            'eligibility_inspection_reserved' => true,
            'zone_availability_subject_to_technician' => true,
            'excludes' => ['installation', 'uninstallation', 'major_repairs', 'replacements'],
            // The card's terms, in card order — shown verbatim on the membership page.
            'terms' => [
                'Membership is valid for 11 months from the activation date.',
                'Membership is valid only for the customer\'s registered address.',
                'Spare parts and materials are chargeable.',
                'Additional visits outside the covered scope are chargeable.',
                'Free service visits are limited to 5 eligible service calls.',
                'Unused benefits cannot be carried forward.',
                'Benefits are non-transferable.',
                'Priority service means preference in technician allocation.',
                'Priority service does not guarantee immediate service.',
                'Service availability depends on technician availability.',
                '1CallFix may inspect and determine service eligibility.',
                'Membership does not include installation, uninstallation, major repairs or replacements.',
                'Out-of-scope AC work is chargeable.',
            ],
        ];
    }
}
