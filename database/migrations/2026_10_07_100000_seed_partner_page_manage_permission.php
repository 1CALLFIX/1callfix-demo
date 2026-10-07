<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// REF 1CF-PARTNER-PAGE-001: one grantable permission for the admin "Partner page" screen (and, in B6, the leads list).
// Super Admin always holds it. Additive data only; down() removes exactly what up() added.
return new class extends Migration
{
    private const SLUG = 'partner_page.manage';

    public function up(): void
    {
        $now = now();

        if (! DB::table('permissions')->where('slug', self::SLUG)->exists()) {
            DB::table('permissions')->insert([
                'slug' => self::SLUG, 'label' => 'Manage the public Partner page and partner leads', 'group' => 'Marketing',
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
