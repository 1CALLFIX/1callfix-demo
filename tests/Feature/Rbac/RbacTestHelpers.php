<?php

namespace Tests\Feature\Rbac;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared fixture builders for the RBAC regression suite (Slice 2). Kept
 * deliberately minimal — one country/city/franchise tree, and role grants
 * built directly against the real permissions/roles tables (seeded by
 * migration, not re-invented here) rather than a parallel fixture format.
 */
trait RbacTestHelpers
{
    /**
     * countries.code is string(2) — a single random character (62
     * possibilities) collides often enough across a suite that creates
     * dozens of countries (some single tests create 2+ of their own, e.g.
     * "does not cover a different franchise/city" cases), and Str::random()
     * isn't seeded deterministically per test. A process-lifetime counter
     * over base-36 guarantees uniqueness for the life of the test run
     * without relying on randomness at all.
     */
    private static int $countryCodeCounter = 0;

    protected function makeCountry(): Country
    {
        $code = strtoupper(str_pad(base_convert((string) self::$countryCodeCounter++, 10, 36), 2, '0', STR_PAD_LEFT));

        return Country::create([
            'name' => 'Testland', 'code' => $code,
            'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
    }

    protected function makeCity(?Country $country = null): City
    {
        return City::create([
            'country_id' => ($country ?? $this->makeCountry())->id,
            'name' => 'Test City '.Str::random(6),
            'is_active' => true,
        ]);
    }

    protected function makeFranchise(?City $city = null): Franchise
    {
        $city ??= $this->makeCity();

        return Franchise::create([
            'name' => 'Test Franchise '.Str::random(6),
            'slug' => Str::slug('test-franchise-'.Str::random(8)),
            'city' => $city->name,
            'country_id' => $city->country_id,
            'city_id' => $city->id,
            'status' => 'active',
        ]);
    }

    /** A plain authenticated admin actor with zero role_assignments and a non-admin users.role. */
    protected function makeUserWithNoPermissions(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'No Permission User',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'support',
            'status' => 'active',
        ]);
    }

    protected function makeSuperAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * A user holding exactly one permission, at exactly one scope — the
     * minimal grant needed to exercise a positive-authorization case without
     * relying on any of the seeded system roles (which bundle many
     * permissions together and would make a test's actual grant ambiguous).
     */
    protected function makeUserWithPermission(string $permissionSlug, string $scopeType, ?int $scopeId = null): User
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Scoped User '.Str::random(6),
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'support',
            'status' => 'active',
        ]);

        $permission = Permission::firstOrCreate(['slug' => $permissionSlug], ['label' => $permissionSlug, 'group' => 'Test']);

        $role = Role::create([
            'name' => 'Test Role '.Str::random(6),
            'slug' => 'test-role-'.Str::random(8),
            'description' => 'Test-only role granting '.$permissionSlug,
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        return $user;
    }

    /**
     * Grants an EXISTING actor an additional permission via a second
     * role_assignment, on top of whatever makeUserWithPermission()/
     * makeUserWithNoPermissions() already gave them — for tests that need a
     * realistic multi-permission actor (e.g. holds a screen's entry .view
     * permission plus, separately, the narrower action permission under
     * test), the same way every real seeded system role bundles several
     * permissions rather than holding exactly one.
     */
    protected function grantPermission(User $user, string $permissionSlug, string $scopeType = 'global', ?int $scopeId = null): void
    {
        $permission = Permission::firstOrCreate(['slug' => $permissionSlug], ['label' => $permissionSlug, 'group' => 'Test']);

        $role = Role::create([
            'name' => 'Test Role '.Str::random(6),
            'slug' => 'test-role-'.Str::random(8),
            'description' => 'Test-only role granting '.$permissionSlug,
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);
    }
}
