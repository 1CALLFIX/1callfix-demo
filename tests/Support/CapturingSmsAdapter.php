<?php

namespace Tests\Support;

use App\Contracts\SmsAdapter;

/**
 * Test-only OTP/SMS delivery capture (Part 17: "Create a test OTP delivery
 * provider... captured securely in QA test output"). Bound over the real
 * SmsAdapter contract in tests only (see AuthTestHelpers::bindCapturingSms())
 * — production/QA-runtime binding is untouched (AppServiceProvider still
 * binds LogSmsAdapter by default). Never touches the real LogSmsAdapter or
 * any production log — this is purely an in-memory array for the duration
 * of one test.
 */
class CapturingSmsAdapter implements SmsAdapter
{
    /** @var array<int, array{to:string, message:string}> */
    public array $sent = [];

    public function send(string $to, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return true;
    }

    public function lastMessageTo(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $entry) {
            if ($entry['to'] === $to) {
                return $entry['message'];
            }
        }

        return null;
    }

    /** Extracts the numeric OTP code from the last captured message body — couples to OtpNotification::smsBody()'s copy, deliberately (a real test, not a mock of the message shape). */
    public function lastCodeTo(string $to): ?string
    {
        $message = $this->lastMessageTo($to);
        if (! $message || ! preg_match('/code is (\d+)/', $message, $m)) {
            return null;
        }

        return $m[1];
    }
}
