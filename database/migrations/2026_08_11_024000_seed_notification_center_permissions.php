<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// New capabilities added after the RBAC catalog's initial seed (migration
// 016) already ran -- additive. Granted to Super Admin only for now, same
// reasoning as payouts.manage: broadcast/direct-message capability is
// sensitive by default, assignable to other roles via /admin/roles once a
// real business need for e.g. a Marketing role shows up.
return new class extends Migration
{
    private const SLUGS = [
        'notification.view' => 'View notification campaigns & logs',
        'notification.create' => 'Compose notification campaigns',
        'notification.send' => 'Send notification campaigns immediately',
        'notification.schedule' => 'Schedule notification campaigns',
        'notification.broadcast' => 'Broadcast to an audience (not just one user)',
        'notification.direct' => 'Send a direct message to a specific user',
        'notification.manage_templates' => 'Manage notification templates',
        'notification.view_logs' => 'View delivery-level notification logs',
        'notification.manage_campaigns' => 'Cancel/manage existing campaigns',
        'notification.manage_meetings' => 'Create and manage meetings/announcements',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'Notifications',
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
