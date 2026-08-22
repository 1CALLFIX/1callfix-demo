<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unified "All Users" Directory session — a new, additive aggregation
 * screen (Customers/Providers/Workers/staff, one table) sits ABOVE the
 * existing per-type screens, which keep their own type-specific
 * permissions (customers.view, providers.view, workers.view) unchanged.
 * This is deliberately its own permission, not a reuse of any single
 * type's — seeing the aggregated directory (including staff/admin rows)
 * is a materially broader grant than any one of those. Same additive seed
 * pattern as every prior round (e.g. providers.manage, 2026_08_23_002000).
 */
return new class extends Migration
{
    private const SLUGS = [
        'users.directory.view' => 'View the unified All Users directory (Customers/Providers/Workers/staff)',
    ];

    public function up(): void
    {
        $now = now();

        $rows = collect(self::SLUGS)->map(fn ($label, $slug) => [
            'slug' => $slug, 'label' => $label, 'group' => 'Users',
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
