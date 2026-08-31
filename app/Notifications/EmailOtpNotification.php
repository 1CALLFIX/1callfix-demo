<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Services\OtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivery copy for the custom email verification / password-reset OTP
 * (App\Services\OtpService). Routed on demand — there is not necessarily a
 * User row for the address yet — so it is sent via
 * Notification::route('mail', $email)->notify(...). Kept as a Notification
 * rather than a Mailable so the code copy lives in exactly one place, the
 * same way OtpNotification did for the SMS era.
 */
class EmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $expirySeconds,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $platform = Setting::get('branding.platform_name', '1CallFix');
        $minutes = max(1, (int) round($this->expirySeconds / 60));

        $intro = match ($this->purpose) {
            OtpService::PURPOSE_PASSWORD_RESET => 'Use this code to reset your password.',
            default => 'Use this code to verify your email address.',
        };

        return (new MailMessage)
            ->subject("Your {$platform} verification code: {$this->code}")
            ->greeting('Verification code')
            ->line($intro)
            ->line("**{$this->code}**")
            ->line("This code expires in {$minutes} minute(s). Do not share it with anyone.")
            ->line('If you did not request this, you can ignore this email.');
    }

    /**
     * Plain-text body, exposed for tests and any future non-mail channel.
     * Couples deliberately to the code so a capture-based test reads the
     * real generated value.
     */
    public static function body(string $code, string $purpose, int $expirySeconds): string
    {
        $minutes = max(1, (int) round($expirySeconds / 60));
        $action = $purpose === OtpService::PURPOSE_PASSWORD_RESET ? 'password reset' : 'email verification';

        return "Your 1CallFix {$action} code is {$code}. It expires in {$minutes} minute(s). Do not share this code with anyone.";
    }
}
