<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// franchise_service_pricing has existed since P1 and has a real consumer
// (Bookings\Index's price-override lookup) but no admin write path ever
// existed for it. Same single-.manage-permission pattern as Franchises/
// Zones/Geography — this is a record-management screen, not a browse+
// distinct-action screen like Workers/Customers.
return new class extends Migration
{
    private const SLUG = 'franchise_pricing.manage';
    private const LABEL = 'Manage per-franchise service pricing overrides';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'slug' => self::SLUG, 'label' => self::LABEL, 'group' => 'Franchises',
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
