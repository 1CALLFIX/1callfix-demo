<?php

namespace Tests\Feature\Seo;

use App\Models\City;
use App\Models\Country;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * F3 - the four migrations: rollback guards on the two tables that hold public URL data, the city slug
 * backfill, and the permission seed. (A fifth, unique(services.slug), is deliberately NOT part of this release.)
 */
class F3MigrationGuardsTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require base_path('database/migrations/'.$file);
    }

    public function test_slug_redirects_down_refuses_while_it_holds_rows_and_drops_when_empty(): void
    {
        $migration = $this->migration('2026_10_05_100100_create_slug_redirects_table.php');

        DB::table('slug_redirects')->insert([
            'scope' => 'catalog', 'old_slug' => 'old', 'target_type' => 'category', 'target_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('down() must refuse while rows exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('slug_redirects holds data', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('slug_redirects'));

        DB::table('slug_redirects')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('slug_redirects'));
    }

    public function test_city_page_contents_down_refuses_while_it_holds_rows_and_drops_when_empty(): void
    {
        $migration = $this->migration('2026_10_05_100200_create_city_page_contents_table.php');
        $city = $this->liveCity('Nellore');

        DB::table('city_page_contents')->insert([
            'city_id' => $city->id, 'subject_type' => 'city', 'subject_id' => 0, 'intro' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('down() must refuse while rows exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('city_page_contents holds data', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('city_page_contents'));

        DB::table('city_page_contents')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('city_page_contents'));
    }

    public function test_slug_redirects_is_unique_per_scope_and_old_slug(): void
    {
        $row = ['scope' => 'catalog', 'old_slug' => 'dup', 'target_type' => 'category', 'target_id' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('slug_redirects')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('slug_redirects')->insert($row);
    }

    public function test_the_same_old_slug_may_exist_in_a_different_scope(): void
    {
        $row = ['scope' => 'catalog', 'old_slug' => 'dup', 'target_type' => 'category', 'target_id' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('slug_redirects')->insert($row);
        DB::table('slug_redirects')->insert(['scope' => 'city', 'target_type' => 'city'] + $row);

        $this->assertSame(2, DB::table('slug_redirects')->count());
    }

    public function test_city_slug_backfill_gives_nellore_nellore_and_collisions_a_suffix(): void
    {
        $migration = $this->migration('2026_10_05_100000_add_slug_to_cities_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('cities', 'slug'));

        $country = Country::create(['name' => 'India', 'code' => 'IN', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        foreach (['Nellore', 'Guntur', 'Nellore!'] as $name) {
            DB::table('cities')->insert(['country_id' => $country->id, 'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $slugs = DB::table('cities')->orderBy('id')->pluck('slug', 'name')->all();
        $this->assertSame('nellore', $slugs['Nellore']);
        $this->assertSame('guntur', $slugs['Guntur']);
        $this->assertSame('nellore-2', $slugs['Nellore!']);
    }

    public function test_the_permission_seed_gives_super_admin_both_slugs_and_is_rerunnable(): void
    {
        $migration = $this->migration('2026_10_05_100300_seed_catalog_slug_and_seo_permissions.php');

        foreach (['catalog.edit_slugs', 'seo.edit_city_content'] as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count(), $slug);
        }
        $superAdmin = Role::where('slug', 'super_admin')->first();
        if ($superAdmin) {
            $this->assertTrue($superAdmin->permissions()->where('slug', 'catalog.edit_slugs')->exists());
            $this->assertTrue($superAdmin->permissions()->where('slug', 'seo.edit_city_content')->exists());
        }

        $migration->up(); // idempotent
        $this->assertSame(1, Permission::where('slug', 'catalog.edit_slugs')->count());

        $migration->down();
        $this->assertSame(0, Permission::where('slug', 'catalog.edit_slugs')->count());
        $migration->up();
        $this->assertSame(1, Permission::where('slug', 'seo.edit_city_content')->count());
        $this->assertNotNull(City::query()->count());
    }
}
