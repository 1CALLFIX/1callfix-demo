<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner 2026-11-12: one grantable permission for emergency overrides (App\Support\AdminOverride): start / complete a
// service job, mark a parcel picked up / delivered, assign a rider directly and move a food or shop order on, when the
// partner cannot do it. Checked against the order's own franchise, so a franchise admin acts only on their franchise.
// Super Admin always holds it; roles that can already reassign bookings or parcels get it too, so no dispatcher loses
// what they could do. Additive data only; down() removes exactly what up() added.
return new class extends Migration
{
    private const SLUG = 'orders.emergency_override';

    public function up(): void
    {
        $now = now();

        if (! DB::table('permissions')->where('slug', self::SLUG)->exists()) {
            DB::table('permissions')->insert([
                'slug' => self::SLUG,
                'label' => 'Emergency override: change a job or order status, or assign directly, when the partner cannot (reason required, logged)',
                'group' => 'Bookings',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if (! $permissionId) {
            return;
        }

        $sourceIds = DB::table('permissions')->whereIn('slug', ['bookings.reassign', 'parcel_orders.reassign'])->pluck('id');
        $roleIds = DB::table('permission_role')->whereIn('permission_id', $sourceIds)->pluck('role_id')
            ->push(DB::table('roles')->where('slug', 'super_admin')->value('id'))
            ->filter()->unique();

        foreach ($roleIds as $roleId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                DB::table('permission_role')->insert([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
