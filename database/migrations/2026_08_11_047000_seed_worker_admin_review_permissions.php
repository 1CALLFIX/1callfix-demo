<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Admin-side "review a worker's KYC" capability — the FieldWorker
// equivalent of providers.view/providers.review_kyc (seeded in migration
// 016). Distinct from the B0.1 worker.*/partner.workers.* permissions,
// which are the WORKER's/PARTNER's own self-service capabilities, not an
// admin's. Same additive pattern as every prior permission round.
return new class extends Migration
{
    private const SLUGS = [
        'workers.view' => ['label' => 'View field workers', 'group' => 'Workers'],
        'workers.review_kyc' => ['label' => 'Approve / reject field worker KYC, manage capabilities', 'group' => 'Workers'],
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($meta, $slug) => [
            'slug' => $slug, 'label' => $meta['label'], 'group' => $meta['group'],
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
