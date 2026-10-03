<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MANUAL MONEY ACTIONS approval model (CLAUDE.md) — mismatch refunds get a
// dedicated, grantable permission instead of being Super Admin only. Same
// additive pattern as 2026_08_14_001000_seed_operations_permissions.php:
// only Super Admin holds it on creation; Super Admin assigns it to roles
// (HQ Finance, Franchise Finance, ...) from /admin/roles.
return new class extends Migration
{
    private const SLUG = 'payments.refund_mismatch';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG,
            'label' => 'Refund / approve payments with a mismatched captured amount',
            'group' => 'Finance',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId,
                'permission_id' => DB::table('permissions')->where('slug', self::SLUG)->value('id'),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
