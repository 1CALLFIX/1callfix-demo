<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payment Gateway Manager session — a new, deliberately super-admin-only
 * permission for the new /admin/payment-gateways screen. Unlike every
 * other permission seeded so far (banners.manage, roles.manage, etc.),
 * this one is assigned to NO role except super_admin here -- gateway
 * credentials are the one thing on this platform where "same RBAC lock as
 * everything sensitive" means the strictest lock that exists, not a
 * grantable-anywhere permission a franchise owner could ever hold. Same
 * additive seed pattern as 2026_08_24_001000_seed_users_directory_permission.
 */
return new class extends Migration
{
    private const SLUG = 'payment_gateways.manage';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG,
            'label' => 'Manage payment gateway configuration (credentials, mode, activation)',
            'group' => 'Finance',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
