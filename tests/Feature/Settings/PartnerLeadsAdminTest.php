<?php

namespace Tests\Feature\Settings;

use App\Livewire\PartnerPage\Leads;
use App\Models\ActivityLog;
use App\Models\PartnerLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001, B6: read-only admin leads list, status change, CSV export. */
class PartnerLeadsAdminTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private int $n = 0;

    private function lead(array $over = []): PartnerLead
    {
        $this->n++;

        return PartnerLead::capture($over + [
            'name' => 'Lead '.$this->n, 'phone' => '9'.str_pad((string) $this->n, 9, '0', STR_PAD_LEFT), 'city' => 'Nellore',
            'role' => 'service', 'status' => 'new', 'consent_text' => 'ok', 'acquisition' => null, 'source' => 'partner_page',
        ]);
    }

    private function holder()
    {
        return $this->makeUserWithPermission('partner_page.manage', 'global');
    }

    public function test_the_screen_needs_the_permission(): void
    {
        $this->actingAs($this->makeUserWithPermission('seo.edit_city_content', 'global'))->get(route('admin.partner-leads.index'))->assertForbidden();
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Leads::class)->assertForbidden();

        $this->actingAs($this->holder())->get(route('admin.partner-leads.index'))->assertOk()->assertSee('Partner leads');
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.partner-leads.index'))->assertOk();
    }

    public function test_leads_are_listed_and_all_output_is_escaped(): void
    {
        $this->lead(['name' => '<script>alert(1)</script>', 'city' => '<img src=x onerror=alert(2)>']);

        $html = Livewire::actingAs($this->holder())->test(Leads::class)->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_filters_by_status_and_role_and_paginates(): void
    {
        $this->lead(['name' => 'Waiting Taxi', 'role' => 'taxi', 'status' => 'waitlist']);
        $this->lead(['name' => 'Handed Service', 'role' => 'service', 'status' => 'handed_off']);
        $this->lead(['name' => 'Waiting Food', 'role' => 'food', 'status' => 'waitlist']);

        $c = Livewire::actingAs($this->holder())->test(Leads::class);
        $c->set('statusFilter', 'waitlist')->assertSee('Waiting Taxi')->assertSee('Waiting Food')->assertDontSee('Handed Service');
        $c->set('roleFilter', 'taxi')->assertSee('Waiting Taxi')->assertDontSee('Waiting Food');
        $c->set('statusFilter', '')->set('roleFilter', '')->assertSee('Handed Service');

        for ($i = 0; $i < 25; $i++) {
            $this->lead(['name' => sprintf('Bulk %02d', $i)]);
        }
        $page1 = Livewire::actingAs($this->holder())->test(Leads::class);
        $this->assertSame(20, substr_count($page1->html(), 'data-lead-row'));
        $page1->call('gotoPage', 2, 'page');
        $this->assertSame(8, substr_count($page1->html(), 'data-lead-row')); // 28 leads total
    }

    public function test_status_change_is_audited_validated_and_permission_gated(): void
    {
        $lead = $this->lead(['status' => 'waitlist']);
        $holder = $this->holder();

        Livewire::actingAs($holder)->test(Leads::class)->call('setStatus', $lead->id, 'rejected');
        $this->assertSame('rejected', $lead->fresh()->status);
        $log = ActivityLog::where('subject_type', 'partner_lead')->where('subject_id', $lead->id)->latest('id')->first();
        $this->assertSame($holder->id, $log->causer_id);
        $this->assertSame('waitlist', $log->properties['old']);
        $this->assertSame('rejected', $log->properties['new']);

        Livewire::actingAs($holder)->test(Leads::class)->call('setStatus', $lead->id, 'bogus')->assertHasErrors();
        $this->assertSame('rejected', $lead->fresh()->status);

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Leads::class)->assertForbidden();
        $this->assertSame(1, ActivityLog::where('subject_type', 'partner_lead')->count());
    }

    public function test_the_screen_is_read_only_apart_from_status(): void
    {
        $methods = array_map(fn ($m) => $m->name, (new \ReflectionClass(Leads::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach (['update', 'save', 'delete', 'edit', 'destroy', 'updateLead', 'deleteLead'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
        $this->assertContains('setStatus', $methods);
    }

    public function test_csv_export_is_gated_filtered_logged_and_formula_safe(): void
    {
        $this->lead(['name' => '=HYPERLINK("http://evil","x")', 'city' => '+cmd|calc', 'role' => 'taxi', 'status' => 'waitlist']);
        $this->lead(['name' => '-2+3', 'city' => '@SUM(A1)', 'role' => 'taxi', 'status' => 'waitlist']);
        $this->lead(['name' => "\t=tab", 'city' => "\r=cr", 'role' => 'taxi', 'status' => 'waitlist']);
        $this->lead(['name' => 'Plain Person, "Quoted"', 'city' => 'Nellore', 'role' => 'service', 'status' => 'handed_off']);
        $holder = $this->holder();

        $t = Livewire::actingAs($holder)->test(Leads::class)->set('roleFilter', 'taxi')->call('exportCsv');
        $csv = base64_decode($t->effects['download']['content']);

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd|calc", $csv);
        $this->assertStringContainsString("'-2+3", $csv);
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $this->assertStringContainsString("'\t=tab", $csv);
        $this->assertStringContainsString("'\r=cr", $csv);
        $this->assertStringNotContainsString('Plain Person', $csv, 'the export follows the on-screen filter');
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?[=+\-@]/m', preg_replace('/^.*\n/', '', ltrim($csv, "\xEF\xBB\xBF")), 'no cell may start with a formula character');

        $log = ActivityLog::where('subject_type', 'partner_leads_export')->sole();
        $this->assertSame($holder->id, $log->causer_id);
        $this->assertSame(3, $log->properties['rows']);
        $this->assertSame('taxi', $log->properties['filters']['role']);

        // Not allowed without the permission, and nothing is logged for the refused attempt.
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Leads::class)->assertForbidden();
        $this->assertSame(1, ActivityLog::where('subject_type', 'partner_leads_export')->count());
    }

    public function test_export_headings_hold_no_extra_personal_fields_beyond_the_lead(): void
    {
        $this->lead(['acquisition' => ['utm_source' => 'google']]);
        $csv = base64_decode(Livewire::actingAs($this->holder())->test(Leads::class)->call('exportCsv')->effects['download']['content']);
        $heading = strtok(ltrim($csv, "\xEF\xBB\xBF"), "\n");

        $this->assertSame('id,name,phone,city,role,status,source,utm_source,utm_campaign,consent_at,submit_count,created_at,updated_at', trim($heading));
    }
}
