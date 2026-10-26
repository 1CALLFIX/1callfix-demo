<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner decision 2026-10-26 — each city approves its own partners, so HQ does not review every city. The franchise
// owner may see their franchise's riders and drivers and approve partners' documents (providers and field workers).
// Every screen and document link checks these at the partner's own franchise/zone, so an owner never reaches another
// city's partners. Franchise owner only; Super Admin can untick them, or give them to Operator, in Roles & Permissions.
// Data only: no table or column changes.
return new class extends Migration
{
    private const PERMISSIONS = ['providers.review_kyc', 'workers.view', 'workers.review_kyc'];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        if (! $roleId) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id') as $permissionId) {
            if (! DB::table('permission_role')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id');

        DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->delete();
    }
};
