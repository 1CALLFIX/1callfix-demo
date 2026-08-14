<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// New capability -- a forensic audit of the payment gateway architecture
// this session found that NOTHING in the admin panel shows raw `payments`
// rows (booking/wallet-topup/plan-subscription gateway order attempts,
// their status, or refund amount) -- WalletLedger only shows the CREDIT/
// DEBIT ledger (wallet_transactions), a different table entirely; Payouts
// only covers provider disbursement (the opposite direction). Read-only,
// same single .view permission pattern as every other ledger-visibility
// screen this session (commissions.view, wallets.view, loyalty.view) --
// refund/capture MUTATIONS already happen through their own existing,
// already-authorized paths (booking cancellation -> bookings.cancel; the
// gateway webhook itself, signature-verified) and are not duplicated here.
return new class extends Migration
{
    private const SLUG = 'payments.view';
    private const LABEL = 'View payment transactions (bookings, wallet top-ups, subscriptions)';

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
