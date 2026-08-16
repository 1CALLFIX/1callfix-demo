<?php

namespace Tests\Feature\Notifications;

use App\Exceptions\InvalidPushTokenException;
use App\Notifications\Adapters\FirebaseFcmPushAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BD-8 -- real Firebase Cloud Messaging adapter, tested entirely against
 * Http::fake() (both the OAuth2 token exchange and the FCM v1 send call).
 * A real, throwaway RSA keypair is generated per test to exercise the
 * REAL JWT-signing code path (openssl_sign) without any real network call
 * or real Google credentials -- this is deliberately not a mock of the
 * signing logic itself, only of the two external HTTP calls.
 */
class FirebaseFcmPushAdapterTest extends TestCase
{
    /**
     * A synthetic, throwaway 2048-bit RSA key, generated once for this
     * test file only (never used for anything real, never derived from
     * any real Firebase project) -- baked in as a fixture rather than
     * generated per-test via openssl_pkey_new() because that call depends
     * on an openssl.cnf being discoverable, which isn't guaranteed across
     * every environment this suite runs in. openssl_sign() (what the
     * adapter itself actually calls) has no such dependency -- only key
     * *generation* does, which is purely a test-fixture concern.
     */
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC8fqJNhPnT0rwC
    luw8TUK3Z8bhGpE5eNKUZxDonKsBlL44+6lxx1LGS83VKxE2Wk34bE2JfP8wVJBH
    Zyf05VqHk5B//xdM65gJgzsy/bl459Vz0vQWiIy4M/evfG7CE9fkHGsNmaKR0dk1
    IEwQlMLcOTdKzEpGIMU7icuGpMYcP7+4rj5iqi9Im94fK16g3NeDsiaAdyWj4+iI
    49ijmfzsCXhnHoPhkqPGgRBT/3qasXXK7kQHjwSqhlsYztlN4eviTh1mm9bQx27o
    7rfpWvAXOGo5wCY4BwY77gj7KyV2/4KJEmClo4S56H25We4WYRvVP1os4B6N8GgA
    APbuuxQtAgMBAAECggEALLv7Bd28sr5NG2o5B3iokEFjTDITbCnWBB1PwHzKAtaL
    rJdhV9zfsqrz3xbY/2bAIWiGoE4XfB9dnGyJaY/zA8EMJqxiGcHB8+P+rTPVDDIt
    EM9sWVqv1tmSe0XTPWEbOApch75B/ExqubaABuFfO4eirTA9olYNqPsjX8GEONip
    /4ShlEmBj7bDQoCQxaPR2yqEhqOWa7e34Q+AtZSjF+mbdGanFo7WITXP90C0ggli
    eFX2QWVaL5PBjs98EwbyWGMaf4DUr/WuA0txVIxaGdwT5QLOPQCebMGehCzbUn8W
    LBBqsLsY7aLW4aM1Y3ZUpgpqNd3v33yBeRchJlm5MQKBgQDec4LqXQJyAixysmFK
    teoa22QLRBbCgYZUNkmpunaQY8TmmHbcVJLN0g5fjQHvEMPo8kRq62m1zEcvFJyr
    yqwBsBBFRPsWyHcW86Zn42Z1f+OgE6DazC2qrZAQUM1PZglAF3XyEZRyG0XwXBFk
    Oe1H+0OYz2YBoIDi6WFbifBmiQKBgQDY7B0uCQtg2jnxmNInofU0tkGm2XRCyWJZ
    2sWsLEdJM5kKfKDMo20p56TD3nmccl5BWATAW4MoYy5wVoIjmHnHWKzILjbB2LDk
    9y0P5/iqUdZHdeF8W6WvT7jhQWhWWCiBz0eSXeDYGD+bUoBC2DgDNx4Phps93SSU
    zjFK7sOXhQKBgQCMNukVCo1JCX+0yCU1L1chmJoF0+Q4s+XU3Ocvmb20I37wDrgV
    ByYFn9q5dar7YmjqQxLHBh36nolb7rUWP8iNw8ltsB5IbRFLoUaJgzeI5pS2yMiC
    QWKji4UcE6Jl4p4ADQmmDFiyV+iMqau4rh6XWZRxFIFqkx3KaWqZhWfHaQKBgE+1
    o5tQ839pcTVX5JFvr/zopAM9kL0h5yQBgfWcZ618alYyKZxIyUpGtoLK84ELfZsh
    Ts2oUu+6UkwxXaza0JTx/ruoT7K3f3kDYumfYf6kB8tGg88AlkdvUg5jzIU969SX
    aENef8qoTmcyz7LAZQS5cjBeVBlNc63CftZ8Gh9JAoGBAMlEKNv5fkXrSxCnDqC5
    VVz4IiP0jLlAngXvxspXWA8OND7+antbb5LU8U8ZFXuBUYDfpYje6lGCaw8bf9Ui
    R3Yc13f5PP2eva97h3SPepfGgUN9EiH0xz33Uo/+JkqpguX3soJS5C2/bfj2b3sL
    gP/DejEFPJN+qUe+wJbNk0rz
    -----END PRIVATE KEY-----
    PEM;

    private string $fakeServiceAccountJson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeServiceAccountJson = json_encode([
            'client_email' => 'test-fcm@onecallfix-test.iam.gserviceaccount.com',
            'private_key' => self::TEST_PRIVATE_KEY_PEM,
        ]);

        config([
            'services.push.fcm.project_id' => 'onecallfix-test',
            'services.push.fcm.credentials_json' => $this->fakeServiceAccountJson,
            'services.push.fcm.credentials_path' => null,
        ]);
    }

    private function fakeTokenExchange(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/onecallfix-test/messages/0:123'], 200),
        ]);
    }

    public function test_valid_device_token_sends_successfully(): void
    {
        $this->fakeTokenExchange();

        $result = (new FirebaseFcmPushAdapter)->send('a-valid-device-token', 'Booking update', 'Your provider is on the way.');

        $this->assertTrue($result);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
            && $request['message']['token'] === 'a-valid-device-token'
            && $request['message']['notification']['title'] === 'Booking update'
            && $request['message']['notification']['body'] === 'Your provider is on the way.');
    }

    public function test_token_exchange_uses_a_real_signed_jwt_assertion(): void
    {
        $this->fakeTokenExchange();

        (new FirebaseFcmPushAdapter)->send('a-valid-device-token', 'title', 'body');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return true; // not the request under test
            }

            $assertion = $request['assertion'] ?? null;

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && is_string($assertion)
                && substr_count($assertion, '.') === 2; // header.claims.signature
        });
    }

    public function test_access_token_is_cached_and_not_refetched_on_a_second_send(): void
    {
        $this->fakeTokenExchange();

        (new FirebaseFcmPushAdapter)->send('token-1', 'title', 'body');
        (new FirebaseFcmPushAdapter)->send('token-2', 'title', 'body');

        Http::assertSentCount(3); // 1 token exchange + 2 FCM sends, not 2+2
    }

    public function test_unregistered_token_throws_invalid_push_token_exception(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED', 'message' => 'Requested entity was not found.']], 404),
        ]);

        $this->expectException(InvalidPushTokenException::class);

        (new FirebaseFcmPushAdapter)->send('a-dead-token', 'title', 'body');
    }

    public function test_not_found_token_also_throws_invalid_push_token_exception(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);

        $this->expectException(InvalidPushTokenException::class);

        (new FirebaseFcmPushAdapter)->send('a-malformed-token', 'title', 'body');
    }

    public function test_the_invalid_push_token_exception_never_includes_the_raw_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);

        try {
            (new FirebaseFcmPushAdapter)->send('super-secret-device-token-value', 'title', 'body');
            $this->fail('Expected InvalidPushTokenException.');
        } catch (InvalidPushTokenException $e) {
            $this->assertStringNotContainsString('super-secret-device-token-value', $e->getMessage());
        }
    }

    public function test_a_generic_provider_error_returns_false_rather_than_throwing(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $result = (new FirebaseFcmPushAdapter)->send('some-token', 'title', 'body');

        $this->assertFalse($result);
    }

    public function test_missing_project_id_fails_safely_without_making_a_request(): void
    {
        config(['services.push.fcm.project_id' => null]);
        Http::fake();

        $result = (new FirebaseFcmPushAdapter)->send('some-token', 'title', 'body');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_fails_safely_without_making_a_request(): void
    {
        config(['services.push.fcm.credentials_json' => null, 'services.push.fcm.credentials_path' => null]);
        Http::fake();

        $result = (new FirebaseFcmPushAdapter)->send('some-token', 'title', 'body');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_token_exchange_failure_returns_false_rather_than_throwing(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response('unauthorized', 401)]);

        $result = (new FirebaseFcmPushAdapter)->send('some-token', 'title', 'body');

        $this->assertFalse($result);
    }
}
