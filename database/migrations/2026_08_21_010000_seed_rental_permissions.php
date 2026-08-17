<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// RENTAL MODULE IMPLEMENTATION admin screens. Mirrors
// seed_property_rental_permissions.php's exact convention. super_admin
// only by default.
return new class extends Migration
{
    private const SLUGS = [
        'vehicles.manage' => 'Manage rental vehicles',
        'equipment.manage' => 'Manage rental equipment',
        'rental_reservations.view' => 'View vehicle/equipment rental reservations',
        'rental_reservations.cancel' => 'Cancel vehicle/equipment rental reservations',
    ];

    public function up(): void
    {
        $now = now();
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        foreach (self::SLUGS as $slug => $label) {
            DB::table('permissions')->insert([
                'slug' => $slug, 'label' => $label, 'group' => 'Rental',
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
