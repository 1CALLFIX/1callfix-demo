<?php

namespace App\Notifications;

use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Login/verification OTP delivery copy. OtpService calls SmsAdapter
 * directly for the ad-hoc (no User yet) case — see its docblock for why —
 * and uses smsBody() there so the message text lives in exactly one place
 * either way. This class itself is still real and usable via the normal
 * $user->notify() path for any future case where a User already exists
 * (e.g. re-sending a verification code to an already-registered customer).
 */
class OtpNotification extends Notification
{
    use Queueable;

    public function __construct(private string $code, private string $purpose, private int $expirySeconds)
    {
    }

    public function via($notifiable): array
    {
        return [SmsChannel::class];
    }

    public static function smsBody(string $code, string $purpose, int $expirySeconds): string
    {
        $minutes = max(1, (int) round($expirySeconds / 60));
        $action = match ($purpose) {
            'login' => 'login',
            default => 'verification',
        };

        return "Your 1CallFix {$action} code is {$code}. It expires in {$minutes} minute(s). Do not share this code with anyone.";
    }

    public function toSms($notifiable): string
    {
        return self::smsBody($this->code, $this->purpose, $this->expirySeconds);
    }
}
