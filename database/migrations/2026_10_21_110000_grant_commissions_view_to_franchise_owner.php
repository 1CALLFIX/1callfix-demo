<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner request 2026-10-21 — a franchise owner sees their franchise's earnings (Franchise Earnings, Commissions). Both
// screens scope rows to the holder's franchise. Super Admin can untick it again in Roles & Permissions.
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'commissions.view')->value('id');

        if ($roleId && $permissionId && ! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'commissions.view')->value('id');

        DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->delete();
    }
};
