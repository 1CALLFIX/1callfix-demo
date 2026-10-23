<?php

namespace Tests\Feature\Admin;

use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** Version label on logins / menus / footer, and the System menu hiding the test-only Clear Data on live. */
class AppVersionAndSystemMenuTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_version_label_has_the_release_and_the_commit(): void
    {
        config(['app.version' => '2026.10.23', 'app.build' => 'abc1234']);
        $this->assertSame('v2026.10.23 · abc1234', AppVersion::label());

        config(['app.build' => '']);
        $this->assertSame('v2026.10.23', AppVersion::label());

        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), AppVersion::release());
        $this->assertMatchesRegularExpression('/^([0-9a-f]{7})?$/', AppVersion::commit());
    }

    public function test_version_is_shown_on_the_login_screens_and_the_admin_side_menu(): void
    {
        config(['app.version' => '2026.10.23', 'app.build' => 'abc1234']);

        $this->get(route('admin.login'))->assertOk()->assertSee('v2026.10.23 · abc1234');
        $this->get(route('customer.login'))->assertOk()->assertSee('v2026.10.23 · abc1234');
        $this->get(route('provider.login'))->assertOk()->assertSee('v2026.10.23 · abc1234');
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.operations.index'))->assertOk()->assertSee('v2026.10.23 · abc1234');
    }

    public function test_clear_data_is_in_the_menu_except_in_production(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->get(route('admin.operations.index'))->assertSee(route('admin.operations.data-clear'));

        $this->app['env'] = 'production';
        $this->actingAs($admin)->get(route('admin.operations.index'))->assertDontSee(route('admin.operations.data-clear'))
            ->assertSee(route('admin.operations.backup-restore'));
    }
}
