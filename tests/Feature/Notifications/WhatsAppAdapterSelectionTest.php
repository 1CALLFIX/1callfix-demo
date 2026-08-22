<?php

namespace Tests\Feature\Notifications;

use App\Contracts\WhatsAppAdapter;
use App\Notifications\Adapters\LogWhatsAppAdapter;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Same discipline as ProviderDriverSelectionTest (SMS/Push) — proves the
 * default/unconfigured state resolves to the log adapter, and that the
 * log adapter itself is genuinely a safe no-op (never throws, never
 * requires credentials) rather than merely asserting the binding exists.
 */
class WhatsAppAdapterSelectionTest extends TestCase
{
    public function test_default_unconfigured_whatsapp_driver_resolves_to_the_log_adapter(): void
    {
        config(['services.whatsapp.driver' => 'log']);

        $this->assertInstanceOf(LogWhatsAppAdapter::class, app(WhatsAppAdapter::class));
    }

    public function test_an_unrecognized_whatsapp_driver_value_falls_back_to_the_log_adapter_safely(): void
    {
        config(['services.whatsapp.driver' => 'some-unconfigured-value']);

        $this->assertInstanceOf(LogWhatsAppAdapter::class, app(WhatsAppAdapter::class));
    }

    public function test_log_adapter_writes_to_the_log_and_returns_true_without_any_real_send(): void
    {
        Log::spy();

        $sent = (new LogWhatsAppAdapter())->send('919876543210', 'Daily Digest — 3 bookings today');

        $this->assertTrue($sent);
        Log::shouldHaveReceived('info')->once()->withArgs(function ($message) {
            return str_contains($message, '919876543210') && str_contains($message, 'Daily Digest');
        });
    }
}
