<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// New KYC-specific capabilities. Reuses the EXISTING providers.review_kyc/
// workers.review_kyc permissions for approve/reject/document-viewing (no
// duplicate review permission invented). New here: kyc.assist (franchise
// staff performing an assisted upload -- explicitly NOT approve power),
// kyc.support_requests.create (franchise raises a request), and
// kyc.support_requests.decide (Central Admin ONLY by default -- the
// mission's own separation-of-duties requirement: a franchise-scoped role
// must never hold this).
return new class extends Migration
{
    private const SLUGS = [
        'kyc.assist' => 'Assist a Partner/Worker with KYC document or video upload (Franchise Office)',
        'kyc.support_requests.create' => 'Raise a KYC/withdrawal-restriction support request',
        'kyc.support_requests.decide' => 'Approve/reject/request-more-info on KYC support requests (Central Admin)',
        'kyc.documents.view' => 'View KYC documents & verification videos',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'KYC',
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
