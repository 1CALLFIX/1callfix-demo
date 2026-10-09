<?php

namespace Tests\Feature\Security;

use App\Livewire\Security\Headers as HeadersScreen;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Security\SecurityHeaderSettings as S;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Admin-controlled security headers: safe defaults on, risky ones (HSTS, CSP, Permissions-Policy) off until the owner
 * opts in, https-only HSTS, HTML-only CSP, header-injection safety, and a Super-Admin-only, audit-logged screen.
 */
class SecurityHeadersTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_a_fresh_install_sends_only_the_safe_defaults(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeaderMissing('Strict-Transport-Security');
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Permissions-Policy');
    }

    public function test_the_master_switch_turns_every_header_off(): void
    {
        Setting::set(S::MASTER, '0');

        $this->get('/')
            ->assertHeaderMissing('X-Content-Type-Options')
            ->assertHeaderMissing('Referrer-Policy')
            ->assertHeaderMissing('X-Frame-Options');
    }

    public function test_each_safe_header_has_its_own_switch_and_value(): void
    {
        Setting::set(S::NOSNIFF, '0');
        Setting::set(S::FRAME, 'DENY');
        Setting::set(S::REFERRER, 'no-referrer');

        $this->get('/')
            ->assertHeaderMissing('X-Content-Type-Options')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        Setting::set(S::FRAME_ON, '0');
        $this->get('/')->assertHeaderMissing('X-Frame-Options');
    }

    public function test_hsts_is_opt_in_and_only_sent_over_https(): void
    {
        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        Setting::set(S::HSTS, '1');
        Setting::set(S::HSTS_MAX_AGE, '600');

        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=600');

        Setting::set(S::HSTS_SUBDOMAINS, '1');
        Setting::set(S::HSTS_PRELOAD, '1');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=600; includeSubDomains; preload');
    }

    public function test_preload_is_ignored_without_subdomains(): void
    {
        Setting::set(S::HSTS, '1');
        Setting::set(S::HSTS_PRELOAD, '1');

        $this->assertSame('max-age=31536000', S::hsts());
    }

    public function test_csp_modes_and_html_only(): void
    {
        Setting::set(S::CSP_MODE, 'report_only');
        $this->get('/')
            ->assertHeader('Content-Security-Policy-Report-Only', S::DEFAULT_CSP)
            ->assertHeaderMissing('Content-Security-Policy');

        Setting::set(S::CSP_MODE, 'enforce');
        Setting::set(S::CSP_POLICY, "default-src 'self'");
        $this->get('/')
            ->assertHeader('Content-Security-Policy', "default-src 'self'")
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');

        // JSON never carries a CSP, but still gets the safe headers.
        $this->getJson('/api/categories')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_permissions_policy_is_opt_in(): void
    {
        Setting::set(S::PERMISSIONS, '1');
        $this->get('/')->assertHeader('Permissions-Policy', S::DEFAULT_PERMISSIONS);

        Setting::set(S::PERMISSIONS_VALUE, 'camera=()');
        $this->get('/')->assertHeader('Permissions-Policy', 'camera=()');
    }

    public function test_an_unsafe_stored_value_falls_back_to_the_default(): void
    {
        Setting::set(S::CSP_MODE, 'enforce');
        Setting::set(S::CSP_POLICY, "default-src 'self'\r\nX-Evil: 1");
        Setting::set(S::REFERRER, 'bogus-value');

        $response = $this->get('/');
        $response->assertHeader('Content-Security-Policy', S::DEFAULT_CSP);
        $response->assertHeader('Referrer-Policy', S::DEFAULT_REFERRER);
        $response->assertHeaderMissing('X-Evil');
    }

    public function test_only_a_super_admin_can_open_or_save_the_screen(): void
    {
        $editor = $this->makeUserWithPermission('settings.manage', 'global');
        Livewire::actingAs($editor)->test(HeadersScreen::class)->assertForbidden();

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.security.headers'))->assertOk()->assertSee('Security headers');
    }

    public function test_saving_updates_the_settings_and_is_audit_logged(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(HeadersScreen::class)
            ->set('frame', 'DENY')
            ->set('hsts', true)
            ->set('hstsMaxAge', '3600')
            ->set('cspMode', 'report_only')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('flashMessage', 'Security header settings saved.');

        $this->assertSame('DENY', S::frameOptions());
        $this->assertSame('max-age=3600', S::hsts());
        $this->assertSame('report_only', S::cspMode());
        $this->assertTrue(ActivityLog::where('description', 'like', '%security.headers.hsts%')->exists());
    }

    public function test_the_screen_rejects_multiline_or_out_of_range_input(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(HeadersScreen::class)
            ->set('csp', "default-src 'self'\nX-Evil: 1")
            ->set('hstsMaxAge', '5')
            ->set('referrer', 'nope')
            ->call('save')
            ->assertHasErrors(['csp', 'hstsMaxAge', 'referrer']);
    }
}
