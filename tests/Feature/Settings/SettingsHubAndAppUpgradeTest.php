<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Manage;
use App\Models\Setting;
use App\Support\AppUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-22: "in admin panel → settings still many are showing coming soon, please check and fix". No tab says
 * "soon" any more: App Upgrade is a real setting (read inside the Android apps by public/js/app-update.js) and the
 * rest are signposts to the screens that hold those settings, each link shown only to roles that can open it.
 */
class SettingsHubAndAppUpgradeTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private const IN_APP = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36 1CallFixApp';

    public function test_no_settings_tab_says_coming_soon_and_every_signpost_opens_a_real_screen(): void
    {
        $admin = $this->makeSuperAdmin();
        $page = Livewire::actingAs($admin)->test(Manage::class);

        foreach (array_keys(Manage::HUB_TABS) as $tab) {
            $html = $page->set('activeTab', $tab)->assertOk()->html();
            $this->assertStringNotContainsString('(soon)', $html, "{$tab} still says soon");
            $this->assertStringNotContainsString('coming soon', $html, "{$tab} still says coming soon");
            $this->assertStringContainsString('data-testid="settings-hub-'.$tab.'"', $html);
            foreach (Manage::HUB_TABS[$tab]['links'] as [$label, , $target]) {
                $this->assertStringContainsString(e($label), $html, "{$tab} is missing {$label}");
                if (isset($target['route'])) {
                    $this->assertStringContainsString(route($target['route']), $html);
                }
            }
        }

        $html = $page->set('activeTab', 'app_upgrade')->assertOk()->html();
        $this->assertStringNotContainsString('(soon)', $html);
        $this->assertStringContainsString('data-testid="app-upgrade-customer"', $html);
    }

    public function test_signpost_links_show_only_what_the_role_can_open(): void
    {
        $staff = $this->makeUserWithPermission('settings.manage', 'global');

        $html = Livewire::actingAs($staff)->test(Manage::class)->set('activeTab', 'advanced_system')->html();

        $this->assertStringNotContainsString(route('admin.modules.index'), $html);
        $this->assertStringNotContainsString(route('admin.operations.backup-restore'), $html);
        $this->assertStringContainsString('General / System →', $html, 'Links to other Settings tabs stay visible.');
    }

    public function test_super_admin_saves_app_upgrade_and_a_version_code_is_needed_to_switch_it_on(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('activeTab', 'app_upgrade')
            ->set('appUpgrade.customer.mode', 'ask')
            ->set('appUpgrade.customer.latest', '')
            ->call('saveAppUpgrade')
            ->assertHasErrors('appUpgrade.customer.latest');
        $this->assertSame('off', AppUpgrade::forApp('customer')['mode']);

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('appUpgrade.customer.mode', 'ask')
            ->set('appUpgrade.customer.latest', '94')
            ->set('appUpgrade.rider.mode', 'must')
            ->set('appUpgrade.rider.latest', '88')
            ->call('saveAppUpgrade')
            ->assertHasNoErrors();

        $this->assertSame(['latest' => 94, 'mode' => 'ask'], AppUpgrade::forApp('customer'));
        $this->assertSame(['latest' => 88, 'mode' => 'must'], AppUpgrade::forApp('rider'));
        $this->assertSame(['latest' => null, 'mode' => 'off'], AppUpgrade::forApp('partner'));
        $this->assertSame(['customer', 'rider'], array_keys(AppUpgrade::clientConfig()));
        $this->assertStringContainsString('com.onecall.deliveryboy', AppUpgrade::clientConfig()['rider']['store']);
        $this->assertTrue(\App\Models\ActivityLog::where('description', 'Setting app_upgrade.rider.mode changed')->exists());
    }

    public function test_only_the_super_admin_can_change_app_upgrade(): void
    {
        $staff = $this->makeUserWithPermission('settings.manage', 'global');

        Livewire::actingAs($staff)->test(Manage::class)
            ->set('appUpgrade.customer.mode', 'must')
            ->set('appUpgrade.customer.latest', '999')
            ->call('saveAppUpgrade')
            ->assertHasErrors('permission');

        $this->assertNull(Setting::get(AppUpgrade::modeKey('customer')));
    }

    public function test_the_update_check_is_on_pages_inside_the_app_only_and_only_when_switched_on(): void
    {
        $this->withHeader('User-Agent', self::IN_APP)->get('/')->assertOk()->assertDontSee('js/app-update.js', false);

        Setting::set(AppUpgrade::latestKey('customer'), '94');
        Setting::set(AppUpgrade::modeKey('customer'), 'must');

        $this->withHeader('User-Agent', self::IN_APP)->get('/')->assertOk()
            ->assertSee('js/app-update.js', false)
            ->assertSee('&quot;latest&quot;:94', false)
            ->assertSee('Please update the app to continue', false);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/126')->get('/')->assertOk()
            ->assertDontSee('js/app-update.js', false);
    }
}
