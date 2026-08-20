<?php

namespace App\Services;

use App\Models\Franchise;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     * Platform-structure permissions this codebase restricts to a minimum
     * GRANT scope — i.e. the scope_type of the role_assignment ITSELF,
     * independent of (and in addition to) the ordinary scopeCovers()
     * ancestor check every other permission already gets. Added after a
     * real finding (item 51, then a follow-up policy pass): the usage-side
     * check on module activation was already fixed to validate the
     * SELECTED target scope, but nothing stopped the role-assignment layer
     * from handing a franchise- or zone-scoped role the underlying
     * permission in the first place, regardless of whether every
     * downstream action happened to check it correctly.
     *
     * `modules.manage` -> global only. No seeded system role below
     * super_admin has ever carried it (confirmed by reading
     * 2026_08_11_016000_seed_default_roles_and_permissions.php and
     * 2026_08_16_903000_seed_modules_manage_permission.php directly) — this
     * is a pure closure, not a capability reduction for any real role.
     *
     * `zones.manage` / `franchises.manage` -> global, country, or city.
     * Deliberately NOT reduced to global-only: `country_admin` (zones +
     * franchises) and `city_admin` (zones) are seeded with these
     * permissions by design — each role's own seeded description says so
     * ("Operates one country: franchises, zones... within it") and
     * `FranchisesAuthorizationTest`/`ZonesAuthorizationTest` both cover a
     * country/city-scoped grant successfully managing franchises/zones as
     * correct, load-bearing behavior. What's actually being closed is
     * narrower: a role carrying these permissions (whichever role, seeded
     * or future) must never be assignable at zone or franchise scope —
     * the scope level with no legitimate seeded use case for either
     * permission (franchise_owner/zone_admin/operator/support carry
     * neither).
     */
    private const RESTRICTED_GRANT_SCOPES = [
        'modules.manage' => ['global'],
        'zones.manage' => ['global', 'country', 'city'],
        'franchises.manage' => ['global', 'country', 'city'],
    ];

    /**
     * The real closure, called from Roles\Manage::assign() before a new
     * RoleAssignment is created. Returns the first restricted permission
     * slug the target role carries that $scopeType isn't an allowed grant
     * scope for, or null if the assignment is fine. Deliberately does NOT
     * special-case super_admin-as-actor — this validates the ASSIGNMENT
     * being created, not the permission of whoever's creating it; even an
     * operator with unrestricted roles.manage access shouldn't be able to
     * hand out a franchise-scoped grant of a country-shaped role.
     */
    public function disallowedRestrictedPermission(\App\Models\Role $role, string $scopeType): ?string
    {
        foreach (self::RESTRICTED_GRANT_SCOPES as $permission => $allowedScopes) {
            if ($role->permissions->contains('slug', $permission) && ! in_array($scopeType, $allowedScopes, true)) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * Defense in depth for the three restricted permissions above — same
     * coverage logic as can(), but a covering role_assignment only counts
     * if its OWN scope_type is an allowed grant scope for the permission
     * being checked (see RESTRICTED_GRANT_SCOPES). Protects the usage
     * layer (Modules\Manage::toggle(), Zones\Manage's/Franchises\Manage's
     * mutations) against a hand-crafted, legacy, or otherwise-misconfigured
     * RoleAssignment that assign()'s own check never had a chance to
     * reject — the assignment layer above is the real closure; this does
     * not trust it blindly. Permissions outside RESTRICTED_GRANT_SCOPES
     * behave identically to can() (not used for anything else today).
     */
    public function canWithRestrictedScope(User $user, string $permission, array $scope = []): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        $allowedGrantScopes = self::RESTRICTED_GRANT_SCOPES[$permission] ?? null;

        $assignments = $user->roleAssignments()->with('role.permissions')->get();

        foreach ($assignments as $assignment) {
            $hasPermission = $assignment->role->permissions->contains('slug', $permission);
            if (! $hasPermission) {
                continue;
            }

            if ($allowedGrantScopes !== null && ! in_array($assignment->scope_type, $allowedGrantScopes, true)) {
                continue;
            }

            if ($this->scopeCovers($assignment->scope_type, $assignment->scope_id, $scope)) {
                return true;
            }
        }

        return false;
    }

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

    /**
     * True if ANY of the user's role assignments grant this permission, in
     * any scope at all — for gating a scope-agnostic prerequisite step (e.g.
     * creating a customer record before a zone/franchise has been chosen)
     * where the real, scoped enforcement happens at the next step instead
     * (e.g. Bookings\Index::createBooking()'s own can() call, scoped to the
     * chosen zone). Not a bypass of scoping: it only answers "does this user
     * hold this permission anywhere", never "does it cover a specific
     * target" — every action that actually touches scoped data still goes
     * through can() with a real scope.
     */
    public function canAnywhere(User $user, string $permission): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->roleAssignments()->with('role.permissions')->get()
            ->contains(fn ($assignment) => $assignment->role->permissions->contains('slug', $permission));
    }

    /**
     * Pure geography-covers-target check, no RBAC/User involved -- public
     * because it's reused for non-permission "does this scoped ROW apply to
     * this target location" questions too (e.g. BadgeService checking
     * whether a zone-scoped badge assignment applies to a customer viewing
     * from that zone), not only for can()'s own permission-grant check
     * above. One implementation of "what does global/country/city/zone/
     * franchise scope-covers actually mean" for the whole codebase.
     */
    public function scopeCovers(string $scopeType, ?int $scopeId, array $requestedScope): bool
    {
        if ($scopeType === 'global') {
            return true;
        }

        $requestedId = $requestedScope["{$scopeType}_id"] ?? null;

        return $requestedId !== null && (int) $requestedId === (int) $scopeId;
    }

    /**
     * Restricts $query to rows covered by the user's own grants for
     * $permission -- the row-level counterpart to can()/canAnywhere(),
     * closing the gap those two leave open: canAnywhere() (used to gate
     * screen ENTRY) only proves the user holds the permission SOMEWHERE, not
     * that any given ROW is actually theirs to see. One implementation for
     * every screen rather than 15 bespoke ones.
     *
     * Same additive, fail-safe semantics as can(): super_admin and any
     * 'global'-scoped grant see everything; otherwise the query is limited
     * to the UNION of every covering role_assignment's own geography, via
     * $columns mapping each scope_type this model actually carries onto
     * either a direct column ('zone_id') or a dotted relation path
     * ('booking.franchise.city_id', resolved through Eloquent's own
     * whereHas() dot-notation). A user with zero covering assignments sees
     * nothing -- the query is constrained to an impossible condition, the
     * same "fails safe, not open" behavior scopeCovers() already documents
     * for a single check.
     *
     * @param  array  $columns  e.g. ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id']
     *                          Only include the scope levels this model can actually be filtered by —
     *                          an assignment whose scope_type has no matching key is simply skipped
     *                          (same "no key, no cover" rule scopeCovers() uses). A value may also be
     *                          an array of candidate paths, OR'd together -- for a model like Payment
     *                          that reaches its geography through ONE OF SEVERAL relations depending
     *                          on the row (booking.* for a booking payment, user.* for a wallet top-up
     *                          or subscription payment; the other relation is simply null on that row,
     *                          so its whereHas() harmlessly never matches).
     */
    public function scopeQuery(Builder $query, User $user, string $permission, array $columns): Builder
    {
        if ($user->role === 'super_admin') {
            return $query;
        }

        $assignments = $user->roleAssignments()->with('role.permissions')->get()
            ->filter(fn ($a) => $a->role->permissions->contains('slug', $permission));

        if ($assignments->contains(fn ($a) => $a->scope_type === 'global')) {
            return $query;
        }

        // $columns is keyed the same "{scope_type}_id" way as every scope
        // hint array elsewhere in this codebase (bookingScope(), Plan's own
        // ancestry, can()'s own $scope param) -- scopeCovers() looks up
        // "{$scopeType}_id" too, so the key here must match that shape, not
        // the bare scope_type ('zone', not 'zone_id', is the assignment's
        // own column value).
        $coveringAssignments = $assignments->filter(fn ($a) => isset($columns["{$a->scope_type}_id"]));

        if ($coveringAssignments->isEmpty()) {
            // No grant covers a scope level this model even carries -- fail
            // closed (matches an impossible row) rather than falling open
            // to "no WHERE clause at all = see everything".
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($outer) use ($coveringAssignments, $columns) {
            foreach ($coveringAssignments as $assignment) {
                $candidates = (array) $columns["{$assignment->scope_type}_id"];

                foreach ($candidates as $column) {
                    $lastDot = strrpos($column, '.');

                    if ($lastDot === false) {
                        $outer->orWhere($column, $assignment->scope_id);
                    } else {
                        $relation = substr($column, 0, $lastDot);
                        $relationColumn = substr($column, $lastDot + 1);
                        $outer->orWhereHas($relation, fn ($r) => $r->where($relationColumn, $assignment->scope_id));
                    }
                }
            }
        });
    }

    /**
     * Ancestor-inclusive scope hint for a model whose OWN row carries a
     * single (scope_type, scope_id) pair rather than separate zone_id/
     * franchise_id/city_id/country_id columns -- the Plan Engine's own
     * pattern (Plan::authorizationScopeHint(), unchanged, now delegating
     * here), reused as-is for NotificationCampaign/NotificationMeeting
     * rather than re-implementing the same franchise/zone ancestry walk a
     * third and fourth time. Same shape can()/scopeQuery() expect.
     */
    public function ancestryFor(string $scopeType, ?int $scopeId): array
    {
        if ($scopeType === 'global' || ! $scopeId) {
            return [];
        }

        return match ($scopeType) {
            'franchise' => $this->franchiseAncestry(Franchise::find($scopeId)),
            'zone' => $this->zoneAncestry(Zone::find($scopeId)),
            'city' => ['city_id' => $scopeId],
            'country' => ['country_id' => $scopeId],
            default => [],
        };
    }

    private function franchiseAncestry(?Franchise $franchise): array
    {
        if (! $franchise) {
            return [];
        }

        return array_filter([
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ]);
    }

    private function zoneAncestry(?Zone $zone): array
    {
        if (! $zone) {
            return [];
        }

        $franchise = $zone->franchise;

        return array_filter([
            'zone_id' => $zone->id,
            'franchise_id' => $zone->franchise_id,
            'city_id' => $franchise?->city_id,
            'country_id' => $franchise?->country_id,
        ]);
    }

    /**
     * Filters any collection of models exposing authorizationScopeHint()
     * down to the ones the user's grants for $permission actually cover --
     * the collection-based counterpart to scopeQuery(), for the small
     * number of tables (Plan, NotificationCampaign, NotificationMeeting)
     * that carry their OWN single scope_type/scope_id pair rather than
     * separate geography columns scopeQuery()'s column-map can target
     * directly. Callers fetch a lightweight id+scope_type+scope_id
     * projection first, filter here, then whereIn() the survivors before
     * paginating -- so pagination/counts stay correct rather than being
     * computed before this filter runs.
     */
    public function visibleAmong(iterable $models, User $user, string $permission): Collection
    {
        if ($user->role === 'super_admin') {
            return Collection::make($models);
        }

        return Collection::make($models)
            ->filter(fn ($model) => $this->can($user, $permission, $model->authorizationScopeHint()))
            ->values();
    }
}
