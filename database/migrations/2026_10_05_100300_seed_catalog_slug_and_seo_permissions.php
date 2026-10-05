<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// F3: two grantable permissions, held by Super Admin by default.
//  - catalog.edit_slugs     edit category / subcategory / service slugs (city slugs stay Super Admin only)
//  - seo.edit_city_content  edit a city page's title / meta description / intro (franchise-scoped holders: own city only)
return new class extends Migration
{
    private const SLUGS = [
        'catalog.edit_slugs' => ['Edit catalog slugs (category, subcategory, service)', 'Catalog'],
        'seo.edit_city_content' => ['Edit city page content (title, meta description, intro)', 'SEO'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SLUGS as $slug => [$label, $group]) {
            if (DB::table('permissions')->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('permissions')->insert([
                'slug' => $slug, 'label' => $label, 'group' => $group, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superAdminRoleId) {
            foreach (DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->pluck('id') as $permissionId) {
                $exists = DB::table('permission_role')->where('role_id', $superAdminRoleId)->where('permission_id', $permissionId)->exists();
                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->delete();
    }
};
