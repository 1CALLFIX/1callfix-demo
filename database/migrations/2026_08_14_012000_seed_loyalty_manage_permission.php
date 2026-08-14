<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// loyalty.view (2026_08_11_052000) was deliberately read-only ("no manual
// admin adjustments in v1"). This session adds the first real mutation --
// manually flagging a referral as fraudulent (with wallet clawback if
// already rewarded) -- so a genuine .manage permission is needed
// alongside it, same additive/super-admin-only-by-default pattern as
// every prior round.
return new class extends Migration
{
    private const SLUG = 'loyalty.manage';
    private const LABEL = 'Manage loyalty & referrals (flag fraud, reverse rewards)';

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
