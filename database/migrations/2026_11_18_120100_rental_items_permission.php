<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rent anything (owner 2026-11-18): the team permission to approve posted items, send them back, handle reports and
 * switch items off (`rental_items.manage`), given to Super Admin and every role that already approves property for rent
 * (franchise-scoped holders act only within their franchise). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (! DB::table('permissions')->where('slug', 'rental_items.manage')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'rental_items.manage', 'label' => 'Rent anything: approve posted items, handle reports, switch an item off',
                'group' => 'Rental', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'rental_items.manage')->value('id');
        $sourceId = DB::table('permissions')->where('slug', 'homes.manage')->value('id');
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
        $permissionId = DB::table('permissions')->where('slug', 'rental_items.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
