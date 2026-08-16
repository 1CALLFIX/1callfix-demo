<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Adapters\Msg91SmsAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * BD-8 -- real MSG91 adapter, tested entirely against Http::fake(), never
 * a real network call (Phase 9's own explicit requirement: "Do not
 * require live provider calls in the normal test suite").
 */
class Msg91SmsAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sms.msg91.auth_key' => 'test-auth-key',
            'services.sms.msg91.sender_id' => 'CFXIND',
            'services.sms.msg91.route' => '4',
        ]);
    }

    public function test_sends_to_the_correct_endpoint_with_the_correct_recipient_and_message(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response('1234567890abcdef', 200)]);

        $result = (new Msg91SmsAdapter)->send('+919014609609', 'Your 1CallFix login code is 482913.');

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.msg91.com/api/sendhttp.php')
                && $request['mobiles'] === '919014609609'
                && $request['message'] === 'Your 1CallFix login code is 482913.'
                && $request['sender'] === 'CFXIND'
                && $request['authkey'] === 'test-auth-key';
        });
    }

    public function test_provider_error_response_is_handled_safely_and_returns_false(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Invalid sender id'], 200)]);

        $result = (new Msg91SmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_non_2xx_response_is_handled_safely_and_returns_false(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response('Server Error', 500)]);

        $result = (new Msg91SmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_network_failure_is_caught_and_returns_false_rather_than_throwing(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $result = (new Msg91SmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
    }

    public function test_missing_credentials_fails_safely_without_making_a_request(): void
    {
        config(['services.sms.msg91.auth_key' => null]);
        Http::fake();

        $result = (new Msg91SmsAdapter)->send('+919014609609', 'code 111111');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_the_otp_code_never_appears_in_a_log_line(): void
    {
        Http::fake(['api.msg91.com/*' => Http::response(['type' => 'error'], 200)]);
        $seen = false;
        Log::shouldReceive('error')->atLeast()->once()->andReturnUsing(function ($message, $context = []) use (&$seen) {
            $seen = true;
            $flattened = $message.json_encode($context);
            $this->assertStringNotContainsString('999999', $flattened);
        });

        (new Msg91SmsAdapter)->send('+919014609609', 'Your 1CallFix login code is 999999.');

        $this->assertTrue($seen, 'Expected Log::error to be called at least once.');
    }
}
