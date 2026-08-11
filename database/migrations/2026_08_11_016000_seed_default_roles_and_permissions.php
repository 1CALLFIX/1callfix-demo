<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Seeds the v1 permission catalog (one row per real admin capability that
// exists today — routes/admin.php + the Livewire methods behind them) and
// the seven system roles, then grants each role a permission set consistent
// with what that actor type should actually be able to touch. Super Admin
// gets every permission for completeness/reporting, even though
// AuthorizationService also fast-paths users.role === 'super_admin' to true
// for backward compatibility with the existing EnsureSuperAdmin gate.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissions = [
            ['slug' => 'dashboard.view', 'label' => 'View dashboard', 'group' => 'General'],
            ['slug' => 'bookings.view', 'label' => 'View bookings', 'group' => 'Bookings'],
            ['slug' => 'bookings.create', 'label' => 'Create bookings', 'group' => 'Bookings'],
            ['slug' => 'bookings.cancel', 'label' => 'Cancel bookings', 'group' => 'Bookings'],
            ['slug' => 'bookings.reassign', 'label' => 'Reassign / manually assign bookings', 'group' => 'Bookings'],
            ['slug' => 'providers.view', 'label' => 'View providers', 'group' => 'Providers'],
            ['slug' => 'providers.review_kyc', 'label' => 'Approve / reject provider KYC', 'group' => 'Providers'],
            ['slug' => 'franchises.manage', 'label' => 'Manage franchises', 'group' => 'Geography'],
            ['slug' => 'zones.manage', 'label' => 'Manage zones', 'group' => 'Geography'],
            ['slug' => 'categories.manage', 'label' => 'Manage categories & subcategories', 'group' => 'Catalog'],
            ['slug' => 'services.manage', 'label' => 'Manage services', 'group' => 'Catalog'],
            ['slug' => 'banners.manage', 'label' => 'Manage banners', 'group' => 'Marketing'],
            ['slug' => 'cms.manage', 'label' => 'Manage CMS pages & FAQs', 'group' => 'Marketing'],
            ['slug' => 'settings.manage', 'label' => 'Manage Control Plane settings', 'group' => 'System'],
            ['slug' => 'roles.manage', 'label' => 'Manage roles & permission assignments', 'group' => 'System'],
        ];
        foreach ($permissions as &$p) {
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
        }
        unset($p);
        DB::table('permissions')->insert($permissions);

        $permissionIds = DB::table('permissions')->pluck('id', 'slug');

        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super_admin', 'description' => 'Full access to every module and scope.', 'is_system' => true],
            ['name' => 'Country Admin', 'slug' => 'country_admin', 'description' => 'Operates one country: franchises, zones, providers, bookings within it.', 'is_system' => true],
            ['name' => 'City Admin', 'slug' => 'city_admin', 'description' => 'Operates one city: zones, providers, bookings within it.', 'is_system' => true],
            ['name' => 'Zone Admin', 'slug' => 'zone_admin', 'description' => 'Operates one zone: bookings and providers within it.', 'is_system' => true],
            ['name' => 'Franchise Owner', 'slug' => 'franchise_owner', 'description' => "Operates their own franchise's bookings and providers.", 'is_system' => true],
            ['name' => 'Operator', 'slug' => 'operator', 'description' => 'Day-to-day booking operations: create, view, cancel, reassign.', 'is_system' => true],
            ['name' => 'Support', 'slug' => 'support', 'description' => 'Read-only booking/provider visibility for customer support.', 'is_system' => true],
        ];
        foreach ($roles as &$r) {
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        unset($r);
        DB::table('roles')->insert($roles);

        $roleIds = DB::table('roles')->pluck('id', 'slug');

        $grants = [
            'super_admin' => array_keys($permissionIds->all()),
            'country_admin' => ['dashboard.view', 'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.reassign', 'providers.view', 'providers.review_kyc', 'franchises.manage', 'zones.manage', 'services.manage'],
            'city_admin' => ['dashboard.view', 'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.reassign', 'providers.view', 'providers.review_kyc', 'zones.manage'],
            'zone_admin' => ['dashboard.view', 'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.reassign', 'providers.view'],
            'franchise_owner' => ['dashboard.view', 'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.reassign', 'providers.view'],
            'operator' => ['dashboard.view', 'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.reassign'],
            'support' => ['dashboard.view', 'bookings.view', 'providers.view'],
        ];

        $pivotRows = [];
        foreach ($grants as $roleSlug => $permSlugs) {
            foreach ($permSlugs as $permSlug) {
                $pivotRows[] = [
                    'role_id' => $roleIds[$roleSlug],
                    'permission_id' => $permissionIds[$permSlug],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('permission_role')->insert($pivotRows);
    }

    public function down(): void
    {
        DB::table('permission_role')->whereIn('role_id', DB::table('roles')->pluck('id'))->delete();
        DB::table('roles')->whereIn('slug', ['super_admin', 'country_admin', 'city_admin', 'zone_admin', 'franchise_owner', 'operator', 'support'])->delete();
        DB::table('permissions')->whereIn('slug', [
            'dashboard.view', 'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.reassign',
            'providers.view', 'providers.review_kyc', 'franchises.manage', 'zones.manage', 'categories.manage',
            'services.manage', 'banners.manage', 'cms.manage', 'settings.manage', 'roles.manage',
        ])->delete();
    }
};
