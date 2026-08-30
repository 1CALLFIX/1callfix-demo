<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\Setting;
use App\Notifications\EmailOtpNotification;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression: the auth rebuild made OtpService deliver its code via
 * Notification::route('mail', $email)->notify(...) -- an AnonymousNotifiable
 * with no key, because email-first signup has no User row yet. The global
 * NotificationSent listener in AppServiceProvider then tried to write a
 * notification_logs row keyed on notifiable_id (NOT NULL), producing
 * "Integrity constraint violation: Column 'notifiable_id' cannot be null"
 * and 500-ing every signup / password-reset email.
 *
 * The listener now short-circuits on AnonymousNotifiable: no audit-table
 * row, just a grep-able line in the application log. Notifications are
 * deliberately NOT faked here -- the real event -> listener path is exactly
 * what is under test (EmailOtpTest fakes them, which is why the suite was
 * green while production threw).
 */
class EmailOtpAnonymousLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('auth.otp_resend_cooldown_seconds', '0');
    }

    public function test_generating_an_email_otp_neither_throws_nor_writes_a_notification_log_row(): void
    {
        app(OtpService::class)->generate('nobody@example.com', OtpService::PURPOSE_EMAIL_VERIFY);

        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseHas('otps', [
            'identifier' => 'nobody@example.com',
            'purpose' => OtpService::PURPOSE_EMAIL_VERIFY,
            'status' => Otp::STATUS_PENDING,
        ]);
    }

    public function test_the_anonymous_send_leaves_a_grep_able_application_log_line(): void
    {
        Log::spy();

        app(OtpService::class)->generate('trace@example.com', OtpService::PURPOSE_PASSWORD_RESET);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Anonymous notification sent'
                    && ($context['route']['mail'] ?? null) === 'trace@example.com'
                    && ($context['notification'] ?? null) === EmailOtpNotification::class
                    && ($context['channel'] ?? null) === 'mail';
            })
            ->once();
    }
}
