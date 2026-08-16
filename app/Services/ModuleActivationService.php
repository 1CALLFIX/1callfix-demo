<?php

namespace App\Services;

use App\Models\Module;
use App\Models\ModuleActivation;
use App\Support\Modules as ModuleList;
use InvalidArgumentException;

/**
 * Phase 22.1 (Module Activation Foundation) — the single authoritative
 * answer to "is module X active for this location," closing the gap
 * PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §3/§6 documented: no such
 * mechanism existed anywhere before this phase, at any geography level.
 *
 * Resolution order mirrors Setting::SCOPE_ORDER exactly (zone outranks
 * franchise: zones.franchise_id makes Zone the more specific child, not
 * the other way around) — deliberately reusing an already-established,
 * already-tested convention rather than inventing a second cascade
 * direction for this codebase to hold in its head.
 *
 * Two independent gates, both must pass:
 *   1. Module.is_implemented — a hard, code-level fact. An unimplemented
 *      module (everything except `service` today) can never resolve
 *      active, no matter what any ModuleActivation row says. This is what
 *      makes "unimplemented module != operational module" (mission brief
 *      principle #8) actually true at runtime, not just true by convention.
 *   2. The geography cascade below — explicit ModuleActivation rows,
 *      most-specific-first.
 *
 * Legacy default: if a module IS implemented but no explicit
 * ModuleActivation row exists anywhere in the requested scope's chain, the
 * `service` module specifically defaults ACTIVE — the one documented
 * exception, existing solely so every already-running franchise (and any
 * test/fixture that predates this phase or bypasses FranchiseObserver)
 * keeps working unchanged. This is NOT a precedent: every future module
 * defaults INACTIVE with no row, matching the mission brief's "a newly
 * implemented module should be deployable DISABLED first."
 */
class ModuleActivationService
{
    private const SCOPE_ORDER = ['zone', 'franchise', 'city', 'country'];

    /**
     * @param  array  $scope  e.g. ['zone_id' => 7, 'franchise_id' => 3, 'city_id' => 2, 'country_id' => 1] —
     *                        same `{level}_id` key convention as Setting::get()/AuthorizationService::can().
     *                        Only levels present and non-empty are checked, in SCOPE_ORDER.
     */
    public function isActive(string $moduleCode, array $scope = []): bool
    {
        $module = Module::where('code', $moduleCode)->first();

        if (! $module || ! $module->is_implemented) {
            return false;
        }

        $found = $this->firstExplicitRow($module, $scope);
        if ($found !== null) {
            return $found->is_active;
        }

        return $moduleCode === ModuleList::SERVICE;
    }

    /**
     * The exact scope level (and value) that currently determines a
     * module's effective state, or null if nothing in the chain has an
     * explicit row (the legacy default, if any, is deciding it instead).
     * Backs the admin screen's "inherited" vs "set here" display.
     *
     * @return array{level: string, scope_id: int, is_active: bool}|null
     */
    public function resolvedFrom(string $moduleCode, array $scope = []): ?array
    {
        $module = Module::where('code', $moduleCode)->first();
        if (! $module) {
            return null;
        }

        $row = $this->firstExplicitRow($module, $scope);
        if (! $row) {
            return null;
        }

        return ['level' => $row->scope_type, 'scope_id' => $row->scope_id, 'is_active' => $row->is_active];
    }

    /**
     * Set (create or update) the exact activation row at one specific
     * scope level. Does NOT check Module::is_implemented — an admin is
     * allowed to pre-configure a future module's rollout geography ahead
     * of it shipping (the brief's own "a module registry may contain
     * future modules" allowance); isActive() is what refuses to honor it
     * until the code catches up, not this write path.
     */
    public function setActive(string $moduleCode, string $scopeType, int $scopeId, bool $isActive, ?int $actorUserId = null): ModuleActivation
    {
        if (! in_array($scopeType, ModuleActivation::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException("Invalid module activation scope type: {$scopeType}");
        }

        $module = Module::where('code', $moduleCode)->firstOrFail();

        return ModuleActivation::updateOrCreate(
            ['module_id' => $module->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
            ['is_active' => $isActive, 'created_by_user_id' => $actorUserId]
        );
    }

    private function firstExplicitRow(Module $module, array $scope): ?ModuleActivation
    {
        foreach (self::SCOPE_ORDER as $level) {
            if (empty($scope["{$level}_id"])) {
                continue;
            }

            $row = ModuleActivation::where('module_id', $module->id)
                ->where('scope_type', $level)
                ->where('scope_id', $scope["{$level}_id"])
                ->first();

            if ($row) {
                return $row;
            }
        }

        return null;
    }
}
