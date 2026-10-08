<?php

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanEntitlementTarget;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Str;

/** Admin CRUD for the plan catalog — same shape as every other Manage-screen-backing service in this app. */
class PlanService
{
    public function create(array $data): Plan
    {
        $data['slug'] = $this->uniqueSlug($data['name']);

        return Plan::create($data);
    }

    /**
     * Edits a plan. Price, validity and copy may change at any time — they
     * only ever affect FUTURE purchases and renewals, because every purchased
     * period already snapshotted its balances. What a live subscriber's plan
     * IS (its family and who may hold it) cannot change under them.
     *
     * @throws \RuntimeException
     */
    public function update(Plan $plan, array $data): Plan
    {
        unset($data['slug']);

        if ($plan->subscriptions()->exists()) {
            foreach (['plan_family', 'eligible_actor_type'] as $locked) {
                if (array_key_exists($locked, $data) && $data[$locked] !== $plan->{$locked}) {
                    throw new \RuntimeException("This plan already has subscribers, so its {$locked} cannot be changed.");
                }
            }
        }

        $plan->update($data);

        return $plan->fresh();
    }

    public function toggleActive(Plan $plan): Plan
    {
        $plan->is_active = ! $plan->is_active;
        $plan->save();

        return $plan->fresh();
    }

    /**
     * Copies a package: same price, validity, copy and benefits (quantities, values, catalog targets, enabled state).
     * The copy is inactive and has no subscribers, so it can be edited freely before it goes live.
     */
    public function duplicate(Plan $plan): Plan
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($plan) {
            $copy = $plan->replicate(['slug', 'is_active', 'deleted_at']);
            $copy->name = $plan->name.' (copy)';
            $copy->slug = $this->uniqueSlug($copy->name);
            $copy->is_active = false;
            $copy->save();

            foreach ($plan->entitlements()->with('targets')->get() as $entitlement) {
                $newEntitlement = $entitlement->replicate();
                $newEntitlement->plan_id = $copy->id;
                $newEntitlement->save();

                foreach ($entitlement->targets as $target) {
                    $newTarget = $target->replicate();
                    $newTarget->plan_entitlement_id = $newEntitlement->id;
                    $newTarget->save();
                }
            }

            return $copy->fresh();
        });
    }

    public function addEntitlement(Plan $plan, array $data): PlanEntitlement
    {
        $data['plan_id'] = $plan->id;

        return PlanEntitlement::create($data);
    }

    public function updateEntitlement(PlanEntitlement $entitlement, array $data): PlanEntitlement
    {
        $entitlement->update($data);

        return $entitlement->fresh();
    }

    /** @throws \RuntimeException when the entitlement already has subscriber balances / usage history (see PlanEntitlement::booted()) */
    public function deleteEntitlement(PlanEntitlement $entitlement): void
    {
        $entitlement->delete();
    }

    /**
     * Maps an entitlement onto a real node of the EXISTING master catalog.
     * $choiceKey ties the target to one option of a choose-one entitlement
     * (must be one of its redeem_categories); $excluded makes it a carve-out.
     *
     * @throws \RuntimeException on an unknown catalog row or an invalid choice
     */
    public function addTarget(PlanEntitlement $entitlement, string $type, int $targetId, ?string $choiceKey = null, bool $excluded = false): PlanEntitlementTarget
    {
        if (! in_array($type, PlanEntitlementTarget::TYPES, true)) {
            throw new \RuntimeException("Unknown target type '{$type}'.");
        }

        $exists = match ($type) {
            'category' => ServiceCategory::whereKey($targetId)->exists(),
            'subcategory' => ServiceSubcategory::whereKey($targetId)->exists(),
            'service' => Service::whereKey($targetId)->exists(),
        };
        if (! $exists) {
            throw new \RuntimeException("That {$type} does not exist in the catalog.");
        }

        $choiceKey = $choiceKey !== null && trim($choiceKey) !== '' ? trim($choiceKey) : null;

        if (! $excluded && $entitlement->requiresCategoryChoice()) {
            if ($choiceKey === null || ! in_array($choiceKey, $entitlement->redeem_categories, true)) {
                throw new \RuntimeException('Pick which choice this target belongs to: '.implode(', ', $entitlement->redeem_categories).'.');
            }
        } elseif (! $entitlement->requiresCategoryChoice()) {
            $choiceKey = null;
        }

        return PlanEntitlementTarget::firstOrCreate([
            'plan_entitlement_id' => $entitlement->id,
            'target_type' => $type,
            'target_id' => $targetId,
            'choice_key' => $choiceKey,
            'is_excluded' => $excluded,
        ]);
    }

    public function removeTarget(PlanEntitlementTarget $target): void
    {
        $target->delete();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $i = 1;
        while (Plan::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
