<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\Setting;
use App\Notifications\EmailOtpNotification;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * The custom EMAIL verification / password-reset OTP engine (the repurposed
 * OtpService). Nothing about verification is mocked — only the outbound
 * mail is faked, and the code asserted is the real generated one.
 */
class EmailOtpTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->otp = app(OtpService::class);
        Setting::set('auth.otp_resend_cooldown_seconds', '30');
    }

    public function test_send_then_verify_succeeds_end_to_end(): void
    {
        $email = 'user@example.com';

        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);
        Notification::assertSentOnDemand(EmailOtpNotification::class);

        $code = $this->emailOtpCodeFor($email);
        $result = $this->otp->verify($email, OtpService::PURPOSE_EMAIL_VERIFY, $code);

        $this->assertTrue($result['success']);
        $this->assertSame(Otp::STATUS_VERIFIED, Otp::where('identifier', $email)->latest('id')->first()->status);
    }

    public function test_the_code_is_stored_hashed_never_in_plaintext(): void
    {
        $email = 'hash@example.com';
        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);

        $row = Otp::where('identifier', $email)->sole();
        $this->assertNotSame($this->emailOtpCodeFor($email), $row->code_hash);
        $this->assertSame('email', $row->channel);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $email = 'exp@example.com';
        $this->otp->generate($email, OtpService::PURPOSE_PASSWORD_RESET);
        $code = $this->emailOtpCodeFor($email);

        Otp::where('identifier', $email)->update(['expires_at' => now()->subMinute()]);

        $result = $this->otp->verify($email, OtpService::PURPOSE_PASSWORD_RESET, $code);
        $this->assertFalse($result['success']);
        $this->assertSame('expired', $result['reason']);
    }

    public function test_a_wrong_code_is_rejected_and_counts_an_attempt(): void
    {
        $email = 'wrong@example.com';
        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);

        $result = $this->otp->verify($email, OtpService::PURPOSE_EMAIL_VERIFY, '000000');
        $this->assertFalse($result['success']);
        $this->assertSame('invalid', $result['reason']);
        $this->assertSame(1, Otp::where('identifier', $email)->sole()->attempt_count);
    }

    public function test_repeated_wrong_codes_lock_the_otp(): void
    {
        $email = 'lock@example.com';
        Setting::set('auth.otp_max_attempts', '5');
        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);
        $real = $this->emailOtpCodeFor($email);

        for ($i = 0; $i < 5; $i++) {
            $this->otp->verify($email, OtpService::PURPOSE_EMAIL_VERIFY, '000000');
        }

        $result = $this->otp->verify($email, OtpService::PURPOSE_EMAIL_VERIFY, $real);
        $this->assertFalse($result['success']);
        $this->assertSame('locked', $result['reason']);
    }

    public function test_resend_within_the_cooldown_is_refused(): void
    {
        $email = 'cd@example.com';
        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);

        $this->expectException(\RuntimeException::class);
        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);
    }

    public function test_a_fresh_request_after_cooldown_invalidates_the_previous_code(): void
    {
        $email = 'fresh@example.com';
        Setting::set('auth.otp_resend_cooldown_seconds', '0');

        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);
        $first = $this->emailOtpCodeFor($email);
        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);

        $this->assertFalse($this->otp->verify($email, OtpService::PURPOSE_EMAIL_VERIFY, $first)['success']);
    }

    public function test_purposes_are_isolated(): void
    {
        $email = 'iso@example.com';
        Setting::set('auth.otp_resend_cooldown_seconds', '0');

        $this->otp->generate($email, OtpService::PURPOSE_EMAIL_VERIFY);
        $verifyCode = $this->emailOtpCodeFor($email);

        // A code minted for email_verify must not satisfy password_reset.
        $this->assertFalse($this->otp->verify($email, OtpService::PURPOSE_PASSWORD_RESET, $verifyCode)['success']);
    }
}
