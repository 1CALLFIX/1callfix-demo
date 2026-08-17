<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOTEL / STAY BOOKING MODULE admin screens. Mirrors property_reservations'
 * exact 3-permission convention (Properties\Manage's whole catalog side --
 * property + type + amenities -- covered by one `properties.manage`
 * permission, not one per sub-resource). `accommodations.manage` covers the
 * whole Hotel catalog side the same way: Accommodations, Room Types, Rate
 * Plans, and Room Inventory are all catalog-management surfaces reached
 * from the same admin screen, same authorization boundary, same provider-
 * ownership model -- splitting them into 4 separate permissions would be
 * the "excessive permissions" the mission brief explicitly warns against,
 * with no real access-control distinction to justify it (no evidence any
 * role should manage Room Types but not Rate Plans, etc.). super_admin
 * only by default, same as every prior vertical's first admin-screen pass.
 */
return new class extends Migration
{
    private const SLUGS = [
        'accommodations.manage' => 'Manage hotel/stay accommodations, room types, rate plans and room inventory',
        'hotel_reservations.view' => 'View hotel reservations',
        'hotel_reservations.cancel' => 'Cancel hotel reservations',
    ];

    public function up(): void
    {
        $now = now();
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        foreach (self::SLUGS as $slug => $label) {
            DB::table('permissions')->insert([
                'slug' => $slug, 'label' => $label, 'group' => 'Hotel / Stay',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            if ($superAdminRoleId) {
                $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
                DB::table('permission_role')->insert([
                    'role_id' => $superAdminRoleId, 'permission_id' => $permissionId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = array_keys(self::SLUGS);
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
