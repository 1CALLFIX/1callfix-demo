<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phase B0.1 — prepares the authorization layer for the Worker foundation.
// Same additive pattern as every prior permission seed this session
// (migrations 016/017/024/038): new slugs inserted, granted to Super Admin
// only for now, assignable to real roles later via /admin/roles once a
// business need exists. Does NOT implement the actual Partner-assigns-
// Worker workflow yet -- these permissions exist so the next implementation
// slice has an authorization layer to check against, not because anything
// enforces them yet.
return new class extends Migration
{
    private const SLUGS = [
        'worker.jobs.view' => ['label' => 'View assigned field jobs', 'group' => 'Worker'],
        'worker.jobs.accept' => ['label' => 'Accept/reject field job assignments', 'group' => 'Worker'],
        'worker.availability.manage' => ['label' => 'Manage own online/offline availability', 'group' => 'Worker'],
        'worker.documents.manage' => ['label' => 'Manage own KYC documents', 'group' => 'Worker'],
        'partner.workers.manage' => ['label' => 'Add/remove/suspend workers', 'group' => 'Partner Workforce'],
        'partner.workers.assign' => ['label' => 'Assign field work to a worker', 'group' => 'Partner Workforce'],
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
