<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The vertical registry (master doc §15) — data, not the PHP constant.
 * Seeded from App\Support\Modules::ALL, which remains in place as the
 * shared code/label source Categories/Subcategories/Services already
 * depend on; this table exists for things that need a real row to attach
 * to (settings scope, future per-vertical config), not to replace that
 * shared list.
 *
 * `is_active` = "registered as a real platform vertical" (every row seeds
 * true — all 9 are genuinely on the roadmap). `is_implemented` (Phase 22.1)
 * is the separate, harder question: does this vertical have real
 * operational code behind it right now? Only `service` is true. See
 * ModuleActivationService::isActive() — it refuses to report ANY module
 * active, at any geography scope, unless `is_implemented` is true here,
 * regardless of what any `module_activations` row says. This is what makes
 * "a module registry may contain future modules, but an unimplemented
 * module must never appear as usable merely because its key exists" true
 * at runtime, not just true by convention.
 */
class Module extends Model
{
    protected $table = 'modules';

    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
        'is_implemented',
    ];

    protected $casts = ['is_active' => 'boolean', 'is_implemented' => 'boolean'];

    public function activations()
    {
        return $this->hasMany(ModuleActivation::class);
    }
}
