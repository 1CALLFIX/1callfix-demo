<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Live Visitors (owner 2026-11-21): `visitors.view`, given to Super Admin and every role that already opens the
 * Dashboard (`dashboard.view`), row-scoped the same way (a franchise team sees its own city's visitors). Names and
 * phone numbers of signed-in visitors additionally need `customers.view`. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (! DB::table('permissions')->where('slug', 'visitors.view')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'visitors.view', 'label' => 'Live Visitors: who is on the app and website now, what they view and book',
                'group' => 'Dashboard', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'visitors.view')->value('id');
        $sourceId = DB::table('permissions')->where('slug', 'dashboard.view')->value('id');
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
        $permissionId = DB::table('permissions')->where('slug', 'visitors.view')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
