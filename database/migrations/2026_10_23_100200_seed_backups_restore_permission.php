<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// REF 1CF-BACKUP-RESTORE-001: one grantable permission for System > Backup & Restore (backup status,
// Back up now, Deleted items, Restore one). Super Admin always holds it.
// Additive data only; down() removes exactly what up() added.
return new class extends Migration
{
    private const SLUG = 'backups.restore';

    public function up(): void
    {
        $now = now();

        if (! DB::table('permissions')->where('slug', self::SLUG)->exists()) {
            DB::table('permissions')->insert([
                'slug' => self::SLUG, 'label' => 'Backup & Restore: back up now, restore deleted items and restore from a backup', 'group' => 'System',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($superAdminRoleId && $permissionId
            && ! DB::table('permission_role')->where('role_id', $superAdminRoleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
