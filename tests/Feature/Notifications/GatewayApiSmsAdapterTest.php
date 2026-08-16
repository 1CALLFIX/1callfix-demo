<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Adapters\GatewayApiSmsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BD-8 -- real GatewayAPI adapter, tested entirely against Http::fake().
 * Endpoint/payload shape mirrors the real, working Glover reference
 * implementation (see GatewayApiSmsAdapter's own docblock).
 */
class GatewayApiSmsAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sms.gatewayapi.token' => 'test-token',
            'services.sms.gatewayapi.sender' => '1CallFix',
        ]);
    }

    public function test_sends_to_the_correct_endpoint_with_the_correct_recipient_and_message(): void
    {
        Http::fake(['gatewayapi.com/*' => Http::response(['ids' => [123]], 200)]);

        $result = (new GatewayApiSmsAdapter)->send('+919014609609', 'Your 1CallFix login code is 482913.');

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://gatewayapi.com/rest/mtsms'
                && $request['sender'] === '1CallFix'
                && $request['message'] === 'Your 1CallFix login code is 482913.'
                && $request['recipients'][0]['msisdn'] === '919014609609';
        });
    }

    public function test_uses_http_basic_auth_with_the_token_as_username(): void
    {
        Http::fake(['gatewayapi.com/*' => Http::response(['ids' => [123]], 200)]);

        (new GatewayApiSmsAdapter)->send('+919014609609', 'code 111111');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_non_2xx_response_is_handled_safely_and_returns_false(): void
    {
        Http::fake(['gatewayapi.com/*' => Http::response(['message' => 'Insufficient funds'], 402)]);

        $result = (new GatewayApiSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_network_failure_is_caught_and_returns_false_rather_than_throwing(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $result = (new GatewayApiSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_missing_credentials_fails_safely_without_making_a_request(): void
    {
        config(['services.sms.gatewayapi.token' => null]);
        Http::fake();

        $result = (new GatewayApiSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }
}
