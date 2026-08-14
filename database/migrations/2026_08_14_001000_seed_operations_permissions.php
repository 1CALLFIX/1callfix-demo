<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// New capability -- an Operations/Troubleshoot screen was found completely
// absent in this session's forensic audit (no failed-job visibility, no
// notification-failure visibility, no queue/DB/storage health indicators
// anywhere in the admin panel; support/ops staff had no way to see any of
// this without raw server/DB access). Same additive pattern and same
// super-admin-only-by-default reasoning as every prior permission round
// (e.g. migration 038's plans.view/plans.manage): operational visibility is
// sensitive (exception messages, job payloads can carry PII), and retrying/
// discarding a failed job is a real production action, so both start
// locked down to Super Admin, assignable to other roles via /admin/roles
// once a real business need (e.g. a dedicated Ops role) shows up.
return new class extends Migration
{
    private const SLUGS = [
        'operations.view' => 'View system operations (failed jobs, notification failures, health)',
        'operations.manage' => 'Retry / discard failed jobs',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'System',
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
