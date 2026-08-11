<?php

namespace App\Services;

use App\Models\User;

/**
 * Final RBAC resolution — Global/Country/City/Zone/Module/Franchise scoped,
 * reusing the exact vocabulary Setting's cascade already established
 * (Phase 1). Deliberately NOT a temporary stand-in: this is the model
 * meant to carry the app through to the real Flutter admin/ops surfaces.
 *
 * Unlike Setting::get() (most-specific override WINS, others are ignored),
 * permission grants are additive: a user can hold several role_assignments
 * at different scopes, and access is granted if ANY of them covers the
 * requested scope with a role that has the permission. There's no
 * "overriding" a grant — only adding more of them.
 */
class AuthorizationService
{
    /**
     * @param  array  $scope  e.g. ['zone_id' => 7, 'franchise_id' => 3, 'city_id' => 2, 'country_id' => 1]
     *                        Only the keys relevant to the permission being checked need to be
     *                        passed — an assignment whose scope_type has no matching key in
     *                        $scope simply never covers the request (fails safe, not open).
     */
    public function can(User $user, string $permission, array $scope = []): bool
    {
        // Backward-compatible fast path: super_admin has always meant "full
        // access" via EnsureSuperAdmin, and stays true here rather than
        // becoming dependent on role_assignments rows existing.
        if ($user->role === 'super_admin') {
            return true;
        }

        $assignments = $user->roleAssignments()->with('role.permissions')->get();

        foreach ($assignments as $assignment) {
            $hasPermission = $assignment->role->permissions->contains('slug', $permission);
            if (! $hasPermission) {
                continue;
            }

            if ($this->scopeCovers($assignment->scope_type, $assignment->scope_id, $scope)) {
                return true;
            }
        }

        return false;
    }

    private function scopeCovers(string $scopeType, ?int $scopeId, array $requestedScope): bool
    {
        if ($scopeType === 'global') {
            return true;
        }

        $requestedId = $requestedScope["{$scopeType}_id"] ?? null;

        return $requestedId !== null && (int) $requestedId === (int) $scopeId;
    }
}
