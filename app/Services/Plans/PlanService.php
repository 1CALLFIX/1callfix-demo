<?php

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Support\Str;

/** Admin CRUD for the plan catalog — same shape as every other Manage-screen-backing service in this app. */
class PlanService
{
    public function create(array $data): Plan
    {
        $data['slug'] = $this->uniqueSlug($data['name']);

        return Plan::create($data);
    }

    public function update(Plan $plan, array $data): Plan
    {
        $plan->update($data);

        return $plan->fresh();
    }

    public function toggleActive(Plan $plan): Plan
    {
        $plan->is_active = ! $plan->is_active;
        $plan->save();

        return $plan->fresh();
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

    public function deleteEntitlement(PlanEntitlement $entitlement): void
    {
        $entitlement->delete();
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
