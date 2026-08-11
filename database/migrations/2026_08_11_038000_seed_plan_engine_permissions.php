<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// New capability added after the RBAC catalog's initial seed (migration
// 016) already ran — additive, same pattern as migration 017's
// payouts.manage. Granted to Super Admin only for now: plan/subscription
// records touch pricing and money, no other system role has an explicit
// business reason to hold it yet. Assign via /admin/roles if that changes.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissions = [
            ['slug' => 'plans.view', 'label' => 'View plans & memberships', 'group' => 'Plans'],
            ['slug' => 'plans.manage', 'label' => 'Create & configure plans', 'group' => 'Plans'],
            ['slug' => 'subscriptions.view', 'label' => 'View subscriptions', 'group' => 'Plans'],
            ['slug' => 'subscriptions.manage', 'label' => 'Manage subscriptions (pause/cancel/adjust)', 'group' => 'Plans'],
        ];
        foreach ($permissions as &$p) {
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
        }
        unset($p);
        DB::table('permissions')->insert($permissions);

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['plans.view', 'plans.manage', 'subscriptions.view', 'subscriptions.manage'])
            ->pluck('id');

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            $rows = $permissionIds->map(fn ($id) => [
                'role_id' => $superAdminRoleId,
                'permission_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            DB::table('permission_role')->insert($rows);
        }
    }

    public function down(): void
    {
        $slugs = ['plans.view', 'plans.manage', 'subscriptions.view', 'subscriptions.manage'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
