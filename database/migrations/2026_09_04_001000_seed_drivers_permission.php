<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Users Sidebar Reorganization session — a reserved nav slot for the
 * Parcel vertical's future riders, not a real capability (see
 * App\Livewire\Drivers\Index's own docblock: no queries, no state, no
 * CRUD exists behind this permission yet). super_admin only by default,
 * same as every other genuinely-new capability this codebase has added
 * (see 2026_08_17_007000_seed_parcel_orders_permissions.php's identical
 * comment) — grantable to other roles later, from Roles & Permissions,
 * once the Parcel vertical actually needs it.
 */
return new class extends Migration
{
    private const SLUG = 'drivers.view';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG,
            'label' => 'View drivers (reserved — Parcel vertical, not yet built)',
            'group' => 'Users',
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
