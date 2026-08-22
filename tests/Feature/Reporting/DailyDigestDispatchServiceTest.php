<?php

namespace Tests\Feature\Reporting;

use App\Contracts\WhatsAppAdapter;
use App\Mail\DailyDigestMail;
use App\Models\Setting;
use App\Services\Reporting\DailyDigestDispatchService;
use App\Services\Reporting\DailyDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

class DailyDigestDispatchServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_send_today_mails_every_eligible_recipient_with_an_email_address(): void
    {
        Mail::fake();
        $scenario = $this->makeBookingScenario('completed');

        $superAdmin = $this->makeSuperAdmin();
        $superAdmin->forceFill(['email' => 'super@example.test'])->save();

        // Reuses the scenario's own franchise rather than calling
        // makeFranchise() fresh — RbacTestHelpers and BookingFixtureHelpers
        // each keep their OWN independent country-code counter, and both
        // start at 0, so calling both fixture builders' country-creating
        // paths in the same test collides on countries.code.
        $franchiseAdmin = $this->makeUserWithPermission('dashboard.view', 'franchise', $scenario['franchise']->id);
        $franchiseAdmin->forceFill(['email' => 'franchise-admin@example.test'])->save();

        $noEmailAdmin = $this->makeUserWithPermission('dashboard.view', 'global'); // email stays null

        $result = app(DailyDigestDispatchService::class)->sendToday();

        $this->assertSame(2, $result['recipients'], 'The no-email admin must be excluded — Mail is the required channel.');
        $this->assertSame(2, $result['mailed']);
        Mail::assertSent(DailyDigestMail::class, $superAdmin->email);
        Mail::assertSent(DailyDigestMail::class, $franchiseAdmin->email);
    }

    public function test_a_user_with_no_permission_at_all_is_never_a_recipient(): void
    {
        Mail::fake();
        $this->makeUserWithNoPermissions()->forceFill(['email' => 'nobody@example.test'])->save();

        $result = app(DailyDigestDispatchService::class)->sendToday();

        $this->assertSame(0, $result['recipients']);
        Mail::assertNothingSent();
    }

    public function test_one_recipients_failure_does_not_prevent_the_others_from_being_sent(): void
    {
        Mail::fake();
        $failing = $this->makeSuperAdmin()->forceFill(['email' => 'boom@example.test']);
        $failing->save();
        $ok = $this->makeSuperAdmin()->forceFill(['email' => 'ok@example.test']);
        $ok->save();

        $okPayload = ['kpis' => [
            'bookings_today' => 1, 'bookings_yesterday' => 0, 'completed_today' => 0,
            'completion_rate' => null, 'revenue_today' => 0.0, 'providers_online' => 0, 'providers_total' => 0,
        ], 'insights' => null];

        // Simulate a payload-building failure for exactly ONE recipient
        // (e.g. a bad query/data issue) — the loop must still mail the rest.
        $fakeDigest = \Mockery::mock(DailyDigestService::class);
        $fakeDigest->shouldReceive('forUser')->with(\Mockery::on(fn ($u) => $u->id === $failing->id))->andThrow(new \RuntimeException('boom'));
        $fakeDigest->shouldReceive('forUser')->with(\Mockery::on(fn ($u) => $u->id === $ok->id))->andReturn($okPayload);
        $this->app->instance(DailyDigestService::class, $fakeDigest);

        $result = app(DailyDigestDispatchService::class)->sendToday();

        $this->assertSame(2, $result['recipients']);
        $this->assertSame(1, $result['mailed']);
        $this->assertSame(1, $result['mail_failed']);
        Mail::assertSent(DailyDigestMail::class, $ok->email);
        Mail::assertNotSent(DailyDigestMail::class, fn ($mail) => $mail->recipient->id === $failing->id);
    }

    public function test_whatsapp_is_not_attempted_when_the_setting_is_disabled(): void
    {
        Mail::fake();
        Setting::set('digest.whatsapp_enabled', '0', 'global', null);
        $admin = $this->makeSuperAdmin()->forceFill(['email' => 'wa-off@example.test', 'phone' => '9'.fake()->numerify('#########')]);
        $admin->save();

        $spy = \Mockery::mock(WhatsAppAdapter::class);
        $spy->shouldNotReceive('send');
        $this->app->instance(WhatsAppAdapter::class, $spy);

        $result = app(DailyDigestDispatchService::class)->sendToday();

        $this->assertSame(0, $result['whatsapp_sent']);
    }

    public function test_whatsapp_short_summary_is_sent_when_the_setting_is_enabled_and_recipient_has_a_phone(): void
    {
        Mail::fake();
        Setting::set('digest.whatsapp_enabled', '1', 'global', null);
        $phone = '9'.fake()->numerify('#########');
        $admin = $this->makeSuperAdmin()->forceFill(['email' => 'wa-on@example.test', 'phone' => $phone]);
        $admin->save();

        $spy = \Mockery::mock(WhatsAppAdapter::class);
        $spy->shouldReceive('send')->once()->withArgs(function ($to, $message) use ($phone) {
            // "a shorter summary version (KPIs + insight count, not the full detailed email)"
            return $to === $phone
                && str_contains($message, 'Bookings today')
                && ! str_contains($message, '<html'); // never the HTML email body
        })->andReturn(true);
        $this->app->instance(WhatsAppAdapter::class, $spy);

        $result = app(DailyDigestDispatchService::class)->sendToday();

        $this->assertSame(1, $result['whatsapp_sent']);
    }

    public function test_send_if_due_skips_before_the_configured_time(): void
    {
        Mail::fake();
        Setting::set('digest.send_time_local', '23:59', 'global', null);
        $this->makeSuperAdmin()->forceFill(['email' => 'a@example.test'])->save();

        $result = app(DailyDigestDispatchService::class)->sendIfDue();

        $this->assertTrue($result['skipped']);
        Mail::assertNothingSent();
    }

    public function test_send_if_due_sends_once_the_configured_time_has_passed_and_marks_it_sent(): void
    {
        Mail::fake();
        Setting::set('digest.send_time_local', '00:00', 'global', null);
        $this->makeSuperAdmin()->forceFill(['email' => 'a@example.test'])->save();

        $result = app(DailyDigestDispatchService::class)->sendIfDue();

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['mailed']);
        $this->assertSame(now('Asia/Kolkata')->toDateString(), Setting::get(DailyDigestDispatchService::LAST_SENT_KEY));
    }

    public function test_send_if_due_never_double_sends_within_the_same_day(): void
    {
        Mail::fake();
        Setting::set('digest.send_time_local', '00:00', 'global', null);
        $this->makeSuperAdmin()->forceFill(['email' => 'a@example.test'])->save();

        $first = app(DailyDigestDispatchService::class)->sendIfDue();
        $second = app(DailyDigestDispatchService::class)->sendIfDue();

        $this->assertFalse($first['skipped']);
        $this->assertTrue($second['skipped'], 'A second run the same day (e.g. a re-run schedule:run) must not send twice.');
        Mail::assertSentTimes(DailyDigestMail::class, 1);
    }
}
