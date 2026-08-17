<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Adapters\ArkeselSmsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real Arkesel adapter -- the SMS gateway confirmed ACTUALLY configured
 * (real credentials populated) in the real Glover 1.8.5 reference
 * deployment's own environment (see ArkeselSmsAdapter's docblock). Tested
 * entirely against Http::fake(), never a real network call, matching
 * every sibling adapter test (GatewayApiSmsAdapterTest, Msg91SmsAdapterTest).
 */
class ArkeselSmsAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sms.arkesel.api_key' => 'test-api-key',
            'services.sms.arkesel.sender' => '1CallFix',
        ]);
    }

    public function test_sends_to_the_correct_endpoint_with_the_correct_recipient_and_message(): void
    {
        Http::fake(['sms.arkesel.com/*' => Http::response(['code' => 'ok'], 200)]);

        $result = (new ArkeselSmsAdapter)->send('+919014609609', 'Your 1CallFix login code is 482913.');

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sms.arkesel.com/sms/api')
                && $request['action'] === 'send-sms'
                && $request['api_key'] === 'test-api-key'
                && $request['to'] === '919014609609'
                && $request['from'] === '1CallFix'
                && $request['sms'] === 'Your 1CallFix login code is 482913.';
        });
    }

    public function test_strips_spaces_and_leading_plus_from_the_recipient_number(): void
    {
        Http::fake(['sms.arkesel.com/*' => Http::response(['code' => 'ok'], 200)]);

        (new ArkeselSmsAdapter)->send('+91 901 460 9609', 'code 111111');

        Http::assertSent(fn ($request) => $request['to'] === '919014609609');
    }

    public function test_non_2xx_response_is_handled_safely_and_returns_false(): void
    {
        Http::fake(['sms.arkesel.com/*' => Http::response(['message' => 'Insufficient balance'], 402)]);

        $result = (new ArkeselSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_network_failure_is_caught_and_returns_false_rather_than_throwing(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $result = (new ArkeselSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_missing_credentials_fails_safely_without_making_a_request(): void
    {
        config(['services.sms.arkesel.api_key' => null]);
        Http::fake();

        $result = (new ArkeselSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_missing_sender_fails_safely_without_making_a_request(): void
    {
        config(['services.sms.arkesel.sender' => null]);
        Http::fake();

        $result = (new ArkeselSmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }
}
