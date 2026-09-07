<?php

namespace App\Console\Commands;

use App\Contracts\PushAdapter;
use App\Models\User;
use App\Notifications\Adapters\FirebaseFcmPushAdapter;
use Illuminate\Console\Command;

/**
 * Live FCM verification for Phase 2 push. Resolves the user's stored
 * fcm_token and sends one real notification through the CURRENTLY BOUND
 * PushAdapter, then prints the literal outcome — for FCM, the raw HTTP
 * status and response body from googleapis.com, not just "no exception".
 *
 * Run on the target server (where PUSH_DRIVER=fcm and the service account
 * are configured) after a test device has registered a web token:
 *
 *   php artisan push:test 123
 *   php artisan push:test 123 --title="Ping" --body="Hello" --link=/provider
 */
class PushTest extends Command
{
    protected $signature = 'push:test {user : User id or phone}
        {--title=1CallFix test : Notification title}
        {--body=Live push verification. If you can see this, FCM works. : Notification body}
        {--link= : Optional click-through URL}';

    protected $description = 'Send one real push to a user and print the literal FCM response';

    public function handle(): int
    {
        $key = (string) $this->argument('user');
        $user = ctype_digit($key)
            ? User::find($key)
            : User::where('phone', $key)->first();

        if (! $user) {
            $this->error("No user matched [{$key}].");

            return self::FAILURE;
        }

        if (empty($user->fcm_token)) {
            $this->error("User #{$user->id} ({$user->name}) has no fcm_token — they have not registered a device/browser for push.");

            return self::FAILURE;
        }

        $adapter = app(PushAdapter::class);
        $this->line('Bound adapter : '.$adapter::class);
        $this->line('Target user   : #'.$user->id.' '.$user->name);
        $this->line('Token (head)  : '.substr($user->fcm_token, 0, 12).'…');
        $this->newLine();

        $title = (string) $this->option('title');
        $body = (string) $this->option('body');
        $data = array_filter(['link' => $this->option('link')]);

        if ($adapter instanceof FirebaseFcmPushAdapter) {
            $result = $adapter->sendDiagnostic($user->fcm_token, $title, $body, $data);

            $this->line('HTTP status   : '.($result['status'] ?? 'n/a'));
            $this->line('Response body :');
            $this->line($result['body'] ?? '(none)');
            $this->newLine();

            if ($result['ok']) {
                $this->info('FCM accepted the message.');

                return self::SUCCESS;
            }

            $this->error('FCM did NOT accept the message (see status/body above).');

            return self::FAILURE;
        }

        // LogPushAdapter or any other non-FCM binding.
        $ok = $adapter->send($user->fcm_token, $title, $body, $data);
        $this->warn('Bound adapter is not FirebaseFcmPushAdapter — no real FCM call was made.');
        $this->line('send() returned: '.($ok ? 'true' : 'false').' (check the log for the [PUSH -> …] line).');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
