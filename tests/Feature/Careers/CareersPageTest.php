<?php

namespace Tests\Feature\Careers;

use App\Models\JobOpening;
use App\Models\Setting;
use App\Models\SlugRedirect;
use App\Support\InfoPages\CareersPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Careers page (owner 2026-11-21): /careers lists the open jobs, each job has its own shareable page with Google
 * JobPosting data and an application form; closed or removed jobs keep their page; old addresses 301.
 */
class CareersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_launch_jobs_are_seeded_open_with_no_pay(): void
    {
        $jobs = JobOpening::query()->open()->get();

        $this->assertSame(
            ['partner-onboarding-executive', 'customer-support-executive', 'city-operations-marketing-manager'],
            $jobs->pluck('slug')->all(),
        );
        $this->assertTrue($jobs->every(fn ($j) => $j->pay_text === null && $j->location === 'Nellore'));
        $this->assertNotEmpty($jobs->first()->duties);
    }

    public function test_careers_page_lists_open_jobs_only_with_share_and_partner_band(): void
    {
        JobOpening::where('slug', 'customer-support-executive')->update(['is_open' => false]);
        JobOpening::where('slug', 'partner-onboarding-executive')->update(['pay_text' => '₹15,000 a month + incentives']);

        $this->get('/careers')->assertOk()
            ->assertSee('data-careers-page', false)
            ->assertSee('Partner Onboarding Executive (Field)')
            ->assertSee('City Operations &amp; Marketing Manager', false)
            ->assertDontSee('data-job-card="customer-support-executive"', false)
            ->assertSee('₹15,000 a month + incentives')
            ->assertSee('data-share-button', false)
            ->assertSee('data-careers-partners', false)
            ->assertSee('data-careers-form', false);
    }

    public function test_pay_is_not_shown_when_blank(): void
    {
        $this->get('/careers/customer-support-executive')->assertOk()->assertDontSee('>Pay<', false);
    }

    public function test_a_job_page_has_its_details_form_share_and_job_posting_schema(): void
    {
        $response = $this->get('/careers/partner-onboarding-executive')->assertOk()
            ->assertSee('Partner Onboarding Executive (Field)')
            ->assertSee('Own two-wheeler with a valid driving licence')
            ->assertSee('data-careers-form', false)
            ->assertSee('data-share-button', false)
            ->assertSee('"@type":"JobPosting"', false)
            ->assertSee('"employmentType":"FULL_TIME"', false)
            ->assertSee('"addressLocality":"Nellore"', false);

        $this->assertStringNotContainsString('data-job-closed', $response->getContent());
    }

    public function test_a_closed_or_removed_job_keeps_its_page_and_says_so(): void
    {
        $job = JobOpening::where('slug', 'customer-support-executive')->firstOrFail();
        $job->update(['is_open' => false]);
        $this->get('/careers/customer-support-executive')->assertOk()
            ->assertSee('data-job-closed', false)
            ->assertDontSee('data-careers-form', false)
            ->assertSee('Partner Onboarding Executive (Field)'); // the open jobs are offered instead

        $job->update(['is_open' => true]);
        $job->delete();
        $this->get('/careers/customer-support-executive')->assertOk()->assertSee('data-job-closed', false);
    }

    public function test_a_past_apply_by_date_closes_the_job(): void
    {
        JobOpening::where('slug', 'customer-support-executive')->update(['apply_by' => now()->subDay()->toDateString()]);

        $this->get('/careers')->assertDontSee('data-job-card="customer-support-executive"', false);
        $this->get('/careers/customer-support-executive')->assertSee('data-job-closed', false);
    }

    public function test_an_old_slug_and_the_jobs_addresses_301_with_the_query_kept(): void
    {
        $job = JobOpening::where('slug', 'customer-support-executive')->firstOrFail();
        SlugRedirect::create(['scope' => 'job', 'old_slug' => 'support-executive', 'target_type' => 'job_opening', 'target_id' => $job->id]);

        $this->get('/careers/support-executive?utm_source=wa')->assertStatus(301)
            ->assertRedirect(route('customer.careers.show', 'customer-support-executive').'?utm_source=wa');
        $this->get('/jobs?utm_source=fb')->assertStatus(301)->assertRedirect(route('customer.careers').'?utm_source=fb');
        $this->get('/jobs/customer-support-executive')->assertStatus(301)->assertRedirect(route('customer.careers.show', 'customer-support-executive'));
        $this->get('/careers/no-such-job')->assertNotFound();
    }

    public function test_switching_the_page_off_hides_it_from_the_site_footer_and_sitemap(): void
    {
        $this->get(route('customer.sitemap.static'))->assertOk()->assertSee('/careers/partner-onboarding-executive', false);
        $this->get('/careers')->assertSee('href="'.route('customer.careers').'"', false); // footer link

        Setting::set(CareersPage::ENABLED, '0');

        $this->get('/careers')->assertNotFound();
        $this->get('/careers/partner-onboarding-executive')->assertNotFound();
        $this->get(route('customer.sitemap.static'))->assertOk()->assertDontSee('/careers', false);
        $this->get('/franchise')->assertDontSee('href="'.route('customer.careers').'"', false);
    }
}
