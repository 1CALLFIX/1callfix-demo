<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Coupon engine (design §9) — Marketing group. Only Super Admin holds them on
// creation; Super Admin assigns them to roles from /admin/roles.
return new class extends Migration
{
    private const PERMISSIONS = [
        'coupons.view' => 'View coupons',
        'coupons.manage' => 'Create and edit coupons',
        'coupons.approve' => 'Activate coupons and change funding',
    ];

    public function up(): void
    {
        $now = now();
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        foreach (self::PERMISSIONS as $slug => $label) {
            DB::table('permissions')->insert([
                'slug' => $slug, 'label' => $label, 'group' => 'Marketing',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            if ($superAdminRoleId) {
                DB::table('permission_role')->insert([
                    'role_id' => $superAdminRoleId,
                    'permission_id' => DB::table('permissions')->where('slug', $slug)->value('id'),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $slug) {
            $id = DB::table('permissions')->where('slug', $slug)->value('id');
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
