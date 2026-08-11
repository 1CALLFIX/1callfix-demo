<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Countries/Cities never had their own admin screen or permission -- they
// were only ever readable as dropdown data inside Franchises/Bookings/
// Settings. Distinct from franchises.manage: creating a country/city is a
// higher-level, rarer action than managing a franchise within one. Same
// additive seed pattern as every prior permission round.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $permissionId = DB::table('permissions')->insertGetId([
            'slug' => 'geography.manage', 'label' => 'Manage countries & cities', 'group' => 'Geography',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'geography.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
