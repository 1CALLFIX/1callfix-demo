<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// .view/.manage cover browsing and normal lifecycle (create/schedule/pause/
// submit-for-review), same granularity as badges/flash_sales. .approve is
// deliberately separate -- it is the one permission that unlocks an actual
// financial payout (approved -> rewarded disburses real wallet/points/badge
// rewards), so a franchise-level manager can run a campaign end-to-end
// without ever being the one who authorizes the money leaving, mirroring
// the mission's own "central admin only" separation-of-duties requirement
// for KYC withdrawal decisions.
return new class extends Migration
{
    private const SLUGS = [
        'performance_campaigns.view' => 'View performance/growth campaigns',
        'performance_campaigns.manage' => 'Create and manage performance/growth campaigns',
        'performance_campaigns.approve' => 'Approve performance/growth campaigns and authorize reward payout',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'Growth',
            'created_at' => $now, 'updated_at' => $now,
        ])->values()->all();

        DB::table('permissions')->insert($rows);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->pluck('id');
            DB::table('permission_role')->insert($permissionIds->map(fn ($id) => [
                'role_id' => $superAdminRoleId, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->delete();
    }
};
