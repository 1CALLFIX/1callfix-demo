<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-22: franchisees get the Social posts section for their own city. Their posts wait for HQ approval
 * before going on the main 1CallFix pages (App\Livewire\Marketing\SocialPosts::approve). Only the permission; the
 * franchise owner's role assignment is already franchise-scoped, so they see and plan only their own city's posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'marketing.posts.manage')->value('id');
        if ($roleId && $permissionId && ! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'marketing.posts.manage')->value('id');
        if ($roleId && $permissionId) {
            DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        }
    }
};
