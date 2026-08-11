<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// loyalty_points and referrals are both real, actively-written ledgers
// (LoyaltyService::earn/redeem, ReferralService::createFromSignup/
// qualifyFromCompletedBooking) with no admin browsing screen. Same
// read-only, single .view permission as Wallet Ledger -- no admin
// adjustments in v1.
return new class extends Migration
{
    private const SLUG = 'loyalty.view';
    private const LABEL = 'View loyalty points & referrals';

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
