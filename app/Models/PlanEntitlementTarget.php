<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of "this entitlement covers that catalog node". target_type +
 * target_id reference the EXISTING master catalog (service_categories,
 * service_subcategories, services). See the migration for choice_key /
 * is_excluded semantics.
 */
class PlanEntitlementTarget extends Model
{
    public const TYPES = ['category', 'subcategory', 'service'];

    protected $table = 'plan_entitlement_targets';

    protected $fillable = ['plan_entitlement_id', 'target_type', 'target_id', 'choice_key', 'is_excluded'];

    protected $casts = ['is_excluded' => 'boolean'];

    public function planEntitlement() { return $this->belongsTo(PlanEntitlement::class); }

    /** Does this row point at $service, its subcategory, or its category? */
    public function matches(Service $service): bool
    {
        return match ($this->target_type) {
            'service' => (int) $this->target_id === (int) $service->id,
            'subcategory' => $service->subcategory_id !== null && (int) $this->target_id === (int) $service->subcategory_id,
            'category' => (int) $this->target_id === (int) $service->category_id,
            default => false,
        };
    }

    /** "Category: AC", "Service: AC Jet Pump" — for the admin screen. */
    public function label(): string
    {
        $name = match ($this->target_type) {
            'service' => Service::withTrashed()->find($this->target_id)?->name,
            'subcategory' => ServiceSubcategory::find($this->target_id)?->name,
            'category' => ServiceCategory::find($this->target_id)?->name,
            default => null,
        };

        return ucfirst($this->target_type).': '.($name ?? '#'.$this->target_id.' (missing)');
    }
}
