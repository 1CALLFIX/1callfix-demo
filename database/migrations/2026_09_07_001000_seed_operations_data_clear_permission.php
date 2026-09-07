<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// New capability added after the RBAC catalog's initial seed (migration
// 016) already ran -- additive, not an edit to a shipped migration. Same
// shape as 2026_08_11_017000_seed_payouts_permission.php: granted to
// Super Admin only for now. This tool can permanently delete real
// operational data (mysqldump-verified backup or not) -- at least as
// consequential as payouts.manage's "moves real money" reasoning for that
// same restriction, arguably more so given it's irreversible without a
// manual restore. No other system role has an explicit business reason to
// hold it yet. Assign it to a scoped role via /admin/roles if that
// changes -- though DataClearService::run()'s own environment gate means
// holding this permission is moot everywhere except a non-production
// environment regardless of who holds it.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')->insertGetId([
            'slug' => 'operations.data_clear',
            'label' => 'Clear operational data (non-production only)',
            'group' => 'Operations',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'operations.data_clear')->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
