<?php

namespace Tests\Feature\Careers;

use App\Livewire\Careers\Applications;
use App\Livewire\Careers\Openings;
use App\Models\ActivityLog;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\SlugRedirect;
use App\Support\InfoPages\CareersPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Admin → Careers page and Job applications (owner 2026-11-21): behind `careers.manage`, every change audit-logged, a
 * changed web address keeps the old one working, CVs open only through the gated route.
 */
class CareersAdminTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function manager()
    {
        return $this->makeUserWithPermission('careers.manage', 'global');
    }

    private function application(array $extra = []): JobApplication
    {
        return JobApplication::create($extra + [
            'job_opening_id' => JobOpening::where('slug', 'customer-support-executive')->value('id'),
            'name' => 'Sai Prasad', 'phone' => '9876500001', 'city' => 'Nellore', 'experience' => 'fresher', 'status' => 'new',
        ]);
    }

    public function test_the_permission_is_seeded_to_super_admin(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'careers.manage')->value('id');
        $superAdminRole = DB::table('roles')->where('slug', 'super_admin')->value('id');

        $this->assertNotNull($permissionId);
        if ($superAdminRole) {
            $this->assertTrue(DB::table('permission_role')->where('role_id', $superAdminRole)->where('permission_id', $permissionId)->exists());
        }
    }

    public function test_screens_need_the_permission(): void
    {
        $nobody = $this->makeUserWithPermission('bookings.view', 'global');

        Livewire::actingAs($nobody)->test(Openings::class)->assertForbidden();
        Livewire::actingAs($nobody)->test(Applications::class)->assertForbidden();
    }

    public function test_add_a_job_then_change_its_address_and_the_old_one_redirects(): void
    {
        $admin = $this->manager();

        Livewire::actingAs($admin)->test(Openings::class)
            ->call('create')
            ->set('title', 'Accounts Assistant')->set('team', 'Finance')->set('openings', '1')
            ->set('summary', 'Keep the books and payouts tidy.')
            ->set('duties', "Match payments\n\nPrepare payout lists")
            ->set('payText', '₹14,000 a month')
            ->call('save')->assertHasNoErrors();

        $job = JobOpening::where('title', 'Accounts Assistant')->sole();
        $this->assertSame('accounts-assistant', $job->slug);
        $this->assertSame(['Match payments', 'Prepare payout lists'], $job->duties);
        $this->get('/careers')->assertSee('Accounts Assistant')->assertSee('₹14,000 a month');

        Livewire::actingAs($admin)->test(Openings::class)
            ->call('edit', $job->id)->set('slug', 'accounts-executive')->call('save')->assertHasNoErrors();

        $this->assertSame('accounts-executive', $job->fresh()->slug);
        $this->assertTrue(SlugRedirect::where(['scope' => 'job', 'old_slug' => 'accounts-assistant', 'target_id' => $job->id])->exists());
        $this->get('/careers/accounts-assistant')->assertStatus(301)->assertRedirect(route('customer.careers.show', 'accounts-executive'));
        $this->assertTrue(ActivityLog::where('subject_type', 'job_opening')->where('subject_id', $job->id)->exists());
    }

    public function test_a_taken_or_badly_formed_address_is_refused(): void
    {
        $job = JobOpening::where('slug', 'customer-support-executive')->firstOrFail();

        Livewire::actingAs($this->manager())->test(Openings::class)
            ->call('edit', $job->id)->set('slug', 'partner-onboarding-executive')->call('save')->assertHasErrors('slug')
            ->set('slug', 'Bad Address!')->call('save')->assertHasErrors('slug');
    }

    public function test_close_reopen_and_switch_the_page_off(): void
    {
        $job = JobOpening::where('slug', 'customer-support-executive')->firstOrFail();
        $component = Livewire::actingAs($this->manager())->test(Openings::class);

        $component->call('toggleOpen', $job->id);
        $this->assertFalse($job->fresh()->is_open);
        $component->call('toggleOpen', $job->id);
        $this->assertTrue($job->fresh()->is_open);

        $component->call('togglePage')->assertSet('pageEnabled', false);
        $this->assertFalse(CareersPage::enabled());
    }

    public function test_application_status_and_notes_are_saved_and_logged(): void
    {
        $row = $this->application();

        Livewire::actingAs($this->manager())->test(Applications::class)
            ->assertSee('Sai Prasad')->assertSee('Customer Support Executive (Telugu &amp; English)', false)
            ->call('setStatus', $row->id, 'interview')
            ->call('setStatus', $row->id, 'made-up')->assertHasErrors('status')
            ->call('openNotes', $row->id)->set('notes', 'Interview Monday 11am')->call('saveNotes');

        $row->refresh();
        $this->assertSame('interview', $row->status);
        $this->assertSame('Interview Monday 11am', $row->admin_notes);
        $this->assertSame(2, ActivityLog::where('subject_type', 'job_application')->where('subject_id', $row->id)->count());
    }

    public function test_filters_and_csv_export(): void
    {
        $this->application();
        $this->application(['phone' => '9876500002', 'name' => 'Any Role Person', 'job_opening_id' => null]);

        Livewire::actingAs($this->manager())->test(Applications::class)
            ->set('jobFilter', 'any')->assertSee('Any Role Person')->assertDontSee('Sai Prasad')
            ->set('jobFilter', '')->set('statusFilter', 'hired')->assertDontSee('Any Role Person')
            ->set('statusFilter', '')->call('exportCsv')->assertFileDownloaded();
    }

    public function test_the_cv_opens_only_for_the_team(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('careers/cv/test.pdf', '%PDF-1.4 test');
        $row = $this->application(['cv_path' => 'careers/cv/test.pdf']);

        $this->actingAs($this->makeUserWithPermission('bookings.view', 'global'))
            ->get(route('admin.careers.cv', $row->id))->assertNotFound();

        $this->actingAs($this->manager())->get(route('admin.careers.cv', $row->id))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertTrue(ActivityLog::where('subject_type', 'job_application')->where('description', 'Job application CV opened')->exists());
    }
}
