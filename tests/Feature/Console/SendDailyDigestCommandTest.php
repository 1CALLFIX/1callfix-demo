<?php

namespace Tests\Feature\Console;

use App\Mail\DailyDigestMail;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** Thin artisan-wiring test — the real behaviour is exercised by DailyDigestDispatchServiceTest; this only proves the command is registered and delegates correctly. */
class SendDailyDigestCommandTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_command_sends_the_digest_when_due(): void
    {
        Mail::fake();
        Setting::set('digest.send_time_local', '00:00', 'global', null);
        $this->makeSuperAdmin()->forceFill(['email' => 'a@example.test'])->save();

        $this->artisan('digest:send-daily')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_command_is_a_clean_no_op_when_not_yet_due(): void
    {
        Mail::fake();
        Setting::set('digest.send_time_local', '23:59', 'global', null);
        $this->makeSuperAdmin()->forceFill(['email' => 'a@example.test'])->save();

        $this->artisan('digest:send-daily')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
