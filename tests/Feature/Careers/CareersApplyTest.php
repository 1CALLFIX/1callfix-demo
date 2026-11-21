<?php

namespace Tests\Feature\Careers;

use App\Livewire\Careers\ApplyForm;
use App\Models\JobApplication;
use App\Models\JobOpening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Careers application form (owner 2026-11-21): saves one application per mobile and job, CV as a private PDF,
 * honeypot, throttle, phone check, and the two-wheeler question only where the job needs one.
 */
class CareersApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        RateLimiter::clear('cust-auth:job-apply|ip|127.0.0.1');
    }

    private function job(string $slug): JobOpening
    {
        return JobOpening::where('slug', $slug)->firstOrFail();
    }

    private function form(?JobOpening $job = null)
    {
        return Livewire::test(ApplyForm::class, ['jobId' => $job?->id])
            ->set('name', 'Ravi Kumar')->set('phone', '98765 43210')->set('city', 'Nellore')
            ->set('experience', 'fresher')->set('consent', true);
    }

    public function test_a_valid_application_with_a_cv_is_saved_privately(): void
    {
        $job = $this->job('partner-onboarding-executive');

        $this->form($job)->set('twoWheeler', 'yes')
            ->set('cv', UploadedFile::fake()->create('ravi.pdf', 300, 'application/pdf'))
            ->call('submit')->assertHasNoErrors()->assertSet('done', true)
            ->assertSee('Application sent!')->assertSee('+91 9876543210');

        $row = JobApplication::sole();
        $this->assertSame($job->id, $row->job_opening_id);
        $this->assertSame('9876543210', $row->phone);
        $this->assertSame('new', $row->status);
        $this->assertTrue($row->two_wheeler);
        $this->assertStringStartsWith('careers/cv/', $row->cv_path);
        Storage::disk('local')->assertExists($row->cv_path);
        Storage::disk('public')->assertMissing($row->cv_path);
    }

    public function test_applying_again_for_the_same_job_updates_the_row_and_keeps_the_status(): void
    {
        $job = $this->job('customer-support-executive');
        $this->form($job)->call('submit')->assertHasNoErrors();
        JobApplication::sole()->update(['status' => 'shortlisted']);

        $this->form($job)->set('experience', '1-2')->call('submit')->assertHasNoErrors();

        $row = JobApplication::sole();
        $this->assertSame(2, $row->submit_count);
        $this->assertSame('1-2', $row->experience);
        $this->assertSame('shortlisted', $row->status);
    }

    public function test_any_role_application_has_no_job(): void
    {
        $this->form()->assertSet('job', ApplyForm::ANY)->call('submit')->assertHasNoErrors();

        $this->assertNull(JobApplication::sole()->job_opening_id);
    }

    public function test_two_wheeler_is_asked_only_for_jobs_that_need_one(): void
    {
        $this->form($this->job('partner-onboarding-executive'))->assertSee('two-wheeler and a driving licence')
            ->call('submit')->assertHasErrors('twoWheeler');
        $this->form($this->job('customer-support-executive'))->assertDontSee('two-wheeler and a driving licence')
            ->call('submit')->assertHasNoErrors();
    }

    public function test_a_closed_job_cannot_be_applied_for(): void
    {
        $job = $this->job('customer-support-executive');
        $component = $this->form($job);
        $job->update(['is_open' => false]);

        $component->set('job', (string) $job->id)->call('submit')->assertHasErrors('job');
        $this->assertSame(0, JobApplication::count());
    }

    public function test_the_cv_must_be_a_small_pdf(): void
    {
        $job = $this->job('customer-support-executive');
        $this->form($job)->set('cv', UploadedFile::fake()->create('cv.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->call('submit')->assertHasErrors('cv');
        $this->form($job)->set('cv', UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))
            ->call('submit')->assertHasErrors('cv');
        $this->assertSame(0, JobApplication::count());
    }

    public function test_bad_phone_missing_consent_and_missing_experience_are_refused(): void
    {
        $job = $this->job('customer-support-executive');
        $this->form($job)->set('phone', '12345')->call('submit')->assertHasErrors('phone');
        $this->form($job)->set('consent', false)->call('submit')->assertHasErrors('consent');
        $this->form($job)->set('experience', '')->call('submit')->assertHasErrors('experience');
        $this->assertSame(0, JobApplication::count());
    }

    public function test_honeypot_shows_thanks_and_saves_nothing(): void
    {
        $this->form($this->job('customer-support-executive'))->set('website', 'spam.example')
            ->call('submit')->assertSet('done', true);

        $this->assertSame(0, JobApplication::count());
    }

    public function test_too_many_submits_from_one_mobile_are_throttled(): void
    {
        $job = $this->job('customer-support-executive');
        for ($i = 0; $i < 5; $i++) {
            $this->form($job)->call('submit')->assertHasNoErrors();
        }

        $this->form($job)->call('submit')->assertSet('done', false)->assertSee('Too many attempts');
        $this->assertSame(5, JobApplication::sole()->submit_count);
    }
}
