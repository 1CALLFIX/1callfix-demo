<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// commissions has been a real, actively-written ledger since
// CommissionService::applyForBooking() (every completed booking splits
// into provider/franchise/platform shares here) with no admin browsing
// screen -- Payouts only covers disbursement, never displays the
// commission split itself. Read-only, same single .view permission
// pattern as Wallet Ledger / Loyalty.
return new class extends Migration
{
    private const SLUG = 'commissions.view';
    private const LABEL = 'View commission splits';

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
