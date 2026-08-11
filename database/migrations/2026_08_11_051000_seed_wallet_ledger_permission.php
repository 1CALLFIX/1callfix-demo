<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// wallet_transactions has been a real, actively-written ledger since the
// Wallet phase (WalletService::credit/debit, WalletTopUpService) but never
// had an admin browsing screen — support/ops had no way to see a customer's
// transaction history without a raw DB query. Read-only screen, so a single
// .view permission (no .manage — the ledger is truth, this v1 does not add
// manual admin adjustments, same "don't invent business decisions" reasoning
// as everywhere else this session).
return new class extends Migration
{
    private const SLUG = 'wallets.view';
    private const LABEL = 'View wallet ledger';

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
