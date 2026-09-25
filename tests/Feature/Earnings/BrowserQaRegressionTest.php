<?php

namespace Tests\Feature\Earnings;

use App\Livewire\Customer\Earnings\Loyalty;
use App\Livewire\Operations\Health;
use App\Models\Setting;
use App\Services\SettingsAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixtureValues;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-QA3 — regressions found by walking the Earnings
 * screens in a real browser.
 */
class BrowserQaRegressionTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /**
     * Bug 1: the loyalty rules text printed "redeem@if ($policy['min'] > 0),
     * minimum 100 points@endif" verbatim — Blade does not compile a directive
     * glued to the end of a word (it treats it like an email address).
     * assertSee() on the surrounding text could never notice.
     */
    public function test_loyalty_rules_text_has_no_raw_blade_directives(): void
    {
        EarningsSettingsFixtureValues::loyalty();
        foreach (['earnings.enabled', 'earnings.loyalty_tab'] as $key) {
            Setting::set($key, '1');
        }

        $html = Livewire::actingAs($this->makeCustomer())->test(Loyalty::class)->html();

        $this->assertStringNotContainsString('@if', $html);
        $this->assertStringNotContainsString('@endif', $html);
        $this->assertStringContainsString('minimum 100 points', $html);
    }

    public function test_no_earnings_view_glues_a_blade_directive_to_a_word(): void
    {
        $offenders = [];
        foreach (['customer/earnings', 'earnings-control'] as $dir) {
            foreach (File::allFiles(resource_path("views/livewire/{$dir}")) as $file) {
                if (preg_match('/[A-Za-z0-9_)\]]@(if|endif|else|elseif|unless|endunless|foreach|endforeach|forelse|endforelse)\b/', $file->getContents())) {
                    $offenders[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Bug 2: the Operations activity log showed who changed a setting and
     * when, but never the old and new value — those were only in the stored
     * `properties`. The audit trail must be readable where admins read it.
     */
    public function test_activity_log_shows_old_and_new_value_of_a_setting_change(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::set('wallet.customer_min_topup', '100');
        SettingsAuditor::put($admin, 'wallet.customer_min_topup', '150');

        Livewire::actingAs($admin)->test(Health::class)
            ->assertSee('wallet.customer_min_topup')
            ->assertSee('100 → 150');
    }

    public function test_activity_log_shows_unset_for_a_cleared_setting(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::set('referral.enabled', '1');
        SettingsAuditor::put($admin, 'referral.enabled', null);

        Livewire::actingAs($admin)->test(Health::class)->assertSee('1 → UNSET');
    }

    public function test_activity_log_shows_the_reason_of_a_wallet_adjustment(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::set('wallet.admin_adjustment_max', '1000');
        app(\App\Services\Earnings\WalletAdjustmentService::class)->adjust($admin, $this->makeCustomer(), 'credit', 50, 'goodwill after outage');

        Livewire::actingAs($admin)->test(Health::class)->assertSee('goodwill after outage');
    }
}
