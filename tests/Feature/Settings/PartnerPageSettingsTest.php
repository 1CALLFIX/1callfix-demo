<?php

namespace Tests\Feature\Settings;

use App\Livewire\PartnerPage\Settings as Screen;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Setting;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001, B1: settings keys, admin screen, permission, audit log, publish guard. */
class PartnerPageSettingsTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_the_permission_row_is_seeded_and_super_admin_holds_it(): void
    {
        $this->assertTrue(Permission::where('slug', 'partner_page.manage')->exists());
        $this->assertTrue($this->makeSuperAdmin()->hasPermission('partner_page.manage'));
    }

    public function test_only_a_holder_of_the_permission_can_open_or_save(): void
    {
        $none = $this->makeUserWithPermission('seo.edit_city_content', 'global');
        Livewire::actingAs($none)->test(Screen::class)->assertForbidden();
        $this->actingAs($none)->get(route('admin.partner-page.settings'))->assertForbidden();

        $holder = $this->makeUserWithPermission('partner_page.manage', 'global');
        $this->actingAs($holder)->get(route('admin.partner-page.settings'))->assertOk()->assertSee('Partner page');
    }

    public function test_save_stores_values_audit_logs_and_clears_the_cache(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::get(P::key('hero.title')); // prime the null in the cache
        $this->assertSame('Get job offers from customers near you', P::text('hero.title'));

        Livewire::actingAs($admin)->test(Screen::class)
            ->set('f.hero__title', 'Work with 1CallFix')
            ->set('f.commission__min', '25')->set('f.commission__max', '30')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Work with 1CallFix', P::text('hero.title'));
        $this->assertStringContainsString('25 to 30 percent', (string) P::commissionLine());
        $this->assertGreaterThanOrEqual(3, ActivityLog::where('subject_type', 'setting')->where('causer_id', $admin->id)->count());
        $this->assertSame(1, ActivityLog::where('subject_type', 'partner_page')->count());
    }

    public function test_a_value_with_square_brackets_blocks_publishing(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Screen::class)
            ->set('f.hero__title', 'Join [CITY] today')
            ->call('save')->assertHasErrors(['f.hero__title']);
        $this->assertNull(Setting::get(P::key('hero.title')));

        // also inside a JSON string
        $faq = json_encode([['tab' => 'everyone', 'q' => 'Q?', 'a' => 'Call [NUMBER]']]);
        Livewire::actingAs($admin)->test(Screen::class)->set('f.faq', $faq)->call('save')->assertHasErrors(['f.faq']);
        $this->assertNull(Setting::get(P::key('faq')));
    }

    public function test_json_lists_are_validated_and_capped(): void
    {
        $this->assertArrayHasKey('faq', P::validate(['faq' => json_encode([['tab' => 'nope', 'q' => 'Q', 'a' => 'A']])]));
        $this->assertArrayHasKey('faq', P::validate(['faq' => 'not json']));
        $this->assertArrayHasKey('faq', P::validate(['faq' => json_encode(array_fill(0, 41, ['tab' => 'everyone', 'q' => 'Q', 'a' => 'A']))]));
        $this->assertArrayHasKey('faq', P::validate(['faq' => json_encode([['tab' => 'everyone', 'q' => 'Q', 'a' => str_repeat('x', 30000)]])]));
        $this->assertSame([], P::validate(['faq' => json_encode([['tab' => 'shops', 'q' => 'Q', 'a' => 'A']])]));

        $this->assertArrayHasKey('benefits', P::validate(['benefits' => json_encode([['icon' => 'nope', 'color' => 'blue', 'title' => 'T', 'body' => 'B']])]));
        $this->assertArrayHasKey('modules_hidden', P::validate(['modules_hidden' => json_encode(['not_a_module'])]));
        $this->assertArrayHasKey('store.android_url', P::validate(['store.android_url' => 'http://insecure.example']));
        $this->assertArrayHasKey('commission.max', P::validate(['commission.min' => '30', 'commission.max' => '25']));
        $this->assertArrayHasKey('commission.max', P::validate(['commission.min' => '25']));
    }

    public function test_claims_default_off_and_nothing_is_hardcoded(): void
    {
        foreach (['claims.joining_free', 'claims.company_accounts', 'claims.whatsapp_updates', 'claims.save_finish_later'] as $claim) {
            $this->assertFalse(P::on($claim), $claim);
        }
        $this->assertNull(P::commissionLine(), 'no range configured, so no commission text');
        $this->assertNull(P::text('payout_timing'));
        $this->assertNull(P::text('store.android_url'));
        $this->assertNull(P::retentionDays());
    }

    public function test_the_read_only_fee_warns_when_outside_the_configured_range(): void
    {
        Setting::set('commission.default_platform_fee_percent', '40');

        Livewire::actingAs($this->makeSuperAdmin())->test(Screen::class)
            ->set('f.commission__min', '25')->set('f.commission__max', '30')
            ->assertSee('outside the range');
        Livewire::actingAs($this->makeSuperAdmin())->test(Screen::class)
            ->set('f.commission__min', '25')->set('f.commission__max', '45')
            ->assertDontSee('outside the range');
    }

    public function test_hidden_modules_and_retention_round_trip(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Screen::class)
            ->set('hidden', ['taxi'])->set('f.lead_retention_days', '90')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['taxi'], P::hiddenModules());
        $this->assertSame(90, P::retentionDays());
    }

    public function test_the_footer_list_is_saved_from_the_screen_and_an_unsafe_one_is_refused(): void
    {
        $admin = $this->makeSuperAdmin();
        $good = json_encode([['title' => 'Company', 'links' => [['label' => 'Our story', 'href' => '/our-story']]]]);

        Livewire::actingAs($admin)->test(Screen::class)->set('f.footer__groups', $good)->call('save')->assertHasNoErrors();
        $this->get('/')->assertSeeText('Our story');

        $bad = json_encode([['title' => 'Company', 'links' => [['label' => 'x', 'href' => 'javascript:alert(1)']]]]);
        Livewire::actingAs($admin)->test(Screen::class)->set('f.footer__groups', $bad)->call('save')->assertHasErrors(['f.footer__groups']);
        $this->assertSame($good, Setting::get(P::key('footer.groups')));
    }
}
