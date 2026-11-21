<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Careers page (owner 2026-11-21): `careers.manage` (edit the jobs on /careers and handle job applications), given to
 * Super Admin and every role that already handles partner leads (`partner_page.manage`). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (! DB::table('permissions')->where('slug', 'careers.manage')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'careers.manage', 'label' => 'Careers: edit the jobs on the Careers page and handle job applications',
                'group' => 'Marketing', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'careers.manage')->value('id');
        $sourceId = DB::table('permissions')->where('slug', 'partner_page.manage')->value('id');
        $roleIds = DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id')
            ->push(DB::table('roles')->where('slug', 'super_admin')->value('id'))->filter()->unique();
        foreach ($roleIds as $roleId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'careers.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
