<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Export/Import session — Bulk Pre-Register (Part 3). providers.view
 * already gates the list screen; there was no write-oriented permission
 * for providers at all (providers.review_kyc exists, but is scoped
 * specifically to the KYC approve/reject decision — not appropriate to
 * reuse for "create a pending provider shell", a materially different
 * action). Same additive seed pattern as customers.manage
 * (2026_08_11_049000) — providers.manage gates ProviderPreRegisterImporter
 * only; it does not touch/re-gate KYC review, which stays on
 * providers.review_kyc exactly as before.
 */
return new class extends Migration
{
    private const SLUGS = [
        'providers.manage' => 'Bulk pre-register provider account shells',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'Providers',
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
