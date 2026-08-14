<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Same additive pattern, same super-admin-only-by-default reasoning as
// every prior permission round this session. .view covers browsing sales
// and redemptions; .manage covers the full lifecycle (create/schedule/
// go-live/pause/resume/cancel) and target assignment.
return new class extends Migration
{
    private const SLUGS = [
        'flash_sales.view' => 'View flash sales & redemptions',
        'flash_sales.manage' => 'Manage flash sales (create, schedule, lifecycle, targets)',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'Catalog',
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
