<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Manual compensation application (rain/waiting -- the two types with no
// auto-derivable data source) is its own permission, separate from
// bookings.cancel/bookings.reassign -- it moves real money out of the
// platform/franchise, same "financial mutation gets its own permission"
// pattern as performance_campaigns.approve.
return new class extends Migration
{
    private const SLUG = 'bookings.compensate';
    private const LABEL = 'Manually apply rain/waiting compensation to a booking';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG, 'label' => self::LABEL, 'group' => 'Financial',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId, 'permission_id' => $permissionId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
