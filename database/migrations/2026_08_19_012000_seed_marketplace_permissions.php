<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phase 24 (Marketplace Foundation) admin screens. Mirrors property_
// reservations.'/properties.' exact convention. super_admin only by
// default. `marketplace_categories.manage` mirrors this codebase's own
// existing `categories.manage` (Service catalog) precedent -- taxonomy
// management stays a separate permission from product/store management.
return new class extends Migration
{
    private const SLUGS = [
        'stores.manage' => 'Manage marketplace stores',
        'marketplace_categories.manage' => 'Manage marketplace categories',
        'products.manage' => 'Manage marketplace products',
        'marketplace_orders.view' => 'View marketplace orders',
        'marketplace_orders.cancel' => 'Cancel marketplace orders',
    ];

    public function up(): void
    {
        $now = now();
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        foreach (self::SLUGS as $slug => $label) {
            DB::table('permissions')->insert([
                'slug' => $slug, 'label' => $label, 'group' => 'Marketplace',
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
