<?php

namespace Tests\Feature\Notifications;

use App\Contracts\PushAdapter;
use App\Exceptions\InvalidPushTokenException;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BD-8 Phase 8/9 requirement: "handle invalid Firebase/FCM device tokens
 * safely" / "token cleanup behavior". Tests PushChannel in isolation
 * against a fake PushAdapter, independent of which real provider is
 * eventually bound -- the cleanup behavior is a PushChannel concern, not
 * something duplicated per-adapter.
 */
class PushChannelTokenInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(?string $fcmToken): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test User',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer',
            'status' => 'active',
            'fcm_token' => $fcmToken,
        ]);
    }

    private function notification(): Notification
    {
        return new class extends Notification
        {
            public function via($notifiable): array
            {
                return [];
            }

            public function toPush($notifiable): array
            {
                return ['title' => 'Test', 'body' => 'Test body'];
            }
        };
    }

    public function test_an_invalid_push_token_exception_clears_the_users_stale_fcm_token(): void
    {
        $user = $this->makeUser('a-dead-token');

        $adapter = new class implements PushAdapter
        {
            public function send(string $token, string $title, string $body): bool
            {
                throw new InvalidPushTokenException($token);
            }
        };

        (new PushChannel($adapter))->send($user, $this->notification());

        $this->assertNull($user->fresh()->fcm_token);
    }

    public function test_a_generic_send_failure_does_not_clear_the_token(): void
    {
        $user = $this->makeUser('a-token-that-just-timed-out');

        $adapter = new class implements PushAdapter
        {
            public function send(string $token, string $title, string $body): bool
            {
                return false; // transient failure, not "provider says this token is dead"
            }
        };

        (new PushChannel($adapter))->send($user, $this->notification());

        $this->assertSame('a-token-that-just-timed-out', $user->fresh()->fcm_token);
    }

    public function test_a_successful_send_leaves_the_token_untouched(): void
    {
        $user = $this->makeUser('a-valid-token');

        $adapter = new class implements PushAdapter
        {
            public function send(string $token, string $title, string $body): bool
            {
                return true;
            }
        };

        (new PushChannel($adapter))->send($user, $this->notification());

        $this->assertSame('a-valid-token', $user->fresh()->fcm_token);
    }

    public function test_does_not_clear_a_different_users_token_if_it_happens_to_match_the_stale_one(): void
    {
        // Guards the ===-comparison in PushChannel -- only clears if the
        // failing token is still the CURRENT token on that user record at
        // the moment of failure (defends against a race where the user
        // already re-registered a new device between send() and the
        // exception being caught).
        $user = $this->makeUser('a-newer-token');

        $adapter = new class implements PushAdapter
        {
            public function send(string $token, string $title, string $body): bool
            {
                throw new InvalidPushTokenException('a-different-older-token');
            }
        };

        (new PushChannel($adapter))->send($user, $this->notification());

        $this->assertSame('a-newer-token', $user->fresh()->fcm_token);
    }
}
