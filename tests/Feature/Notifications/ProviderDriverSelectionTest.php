<?php

namespace Tests\Feature\Notifications;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Notifications\Adapters\FirebaseFcmPushAdapter;
use App\Notifications\Adapters\GatewayApiSmsAdapter;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Notifications\Adapters\Msg91SmsAdapter;
use Tests\TestCase;

/**
 * BD-8 -- proves AppServiceProvider's config-driven adapter selection
 * actually works, and (most importantly for the record) that the
 * DEFAULT/unconfigured state still resolves to the log-based adapters --
 * i.e. this change is genuinely additive and doesn't silently switch any
 * environment onto a real provider it didn't opt into.
 */
class ProviderDriverSelectionTest extends TestCase
{
    public function test_default_unconfigured_sms_driver_resolves_to_the_log_adapter(): void
    {
        config(['services.sms.driver' => 'log']);

        $this->assertInstanceOf(LogSmsAdapter::class, app(SmsAdapter::class));
    }

    public function test_default_unconfigured_push_driver_resolves_to_the_log_adapter(): void
    {
        config(['services.push.driver' => 'log']);

        $this->assertInstanceOf(LogPushAdapter::class, app(PushAdapter::class));
    }

    public function test_an_unrecognized_sms_driver_value_falls_back_to_the_log_adapter_safely(): void
    {
        config(['services.sms.driver' => 'some-typo-value']);

        $this->assertInstanceOf(LogSmsAdapter::class, app(SmsAdapter::class));
    }

    public function test_msg91_driver_resolves_to_the_msg91_adapter(): void
    {
        config(['services.sms.driver' => 'msg91']);

        $this->assertInstanceOf(Msg91SmsAdapter::class, app(SmsAdapter::class));
    }

    public function test_gatewayapi_driver_resolves_to_the_gatewayapi_adapter(): void
    {
        config(['services.sms.driver' => 'gatewayapi']);

        $this->assertInstanceOf(GatewayApiSmsAdapter::class, app(SmsAdapter::class));
    }

    public function test_fcm_driver_resolves_to_the_firebase_adapter(): void
    {
        config(['services.push.driver' => 'fcm']);

        $this->assertInstanceOf(FirebaseFcmPushAdapter::class, app(PushAdapter::class));
    }
}
