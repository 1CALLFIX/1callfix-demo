<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Layer 1 (Cloudflare in front): the visitor's real address is taken from Cloudflare only, never from anyone else. */
class CloudflareTrustedProxyTest extends TestCase
{
    /** Runs a request through the app's own proxy rules (bootstrap/app.php) and returns [address, https?]. */
    private function seen(string $from, array $headers = []): array
    {
        $server = ['REMOTE_ADDR' => $from];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('http://www.1callfix.com/', 'GET', [], [], [], $server);

        return app(TrustProxies::class)->handle($request, fn (Request $r) => [$r->ip(), $r->isSecure()]);
    }

    public function test_the_global_middleware_stack_includes_the_proxy_rules(): void
    {
        $this->assertTrue(app(\Illuminate\Contracts\Http\Kernel::class)->hasMiddleware(TrustProxies::class));
    }

    public function test_a_request_through_cloudflare_reports_the_customers_own_address_and_https(): void
    {
        $this->assertSame(['49.205.1.2', true], $this->seen('172.70.10.20', ['X-Forwarded-For' => '49.205.1.2', 'X-Forwarded-Proto' => 'https']));
    }

    public function test_a_faked_address_sent_through_cloudflare_is_not_believed(): void
    {
        // Cloudflare adds the real address at the end of whatever the visitor sent.
        $this->assertSame('49.205.1.2', $this->seen('162.158.5.6', ['X-Forwarded-For' => '1.2.3.4, 49.205.1.2'])[0]);
    }

    public function test_cloudflare_ipv6_addresses_are_trusted(): void
    {
        $this->assertSame('49.205.1.2', $this->seen('2606:4700:10::ac43:1', ['X-Forwarded-For' => '49.205.1.2'])[0]);
    }

    public function test_anyone_else_cannot_fake_their_address_or_https(): void
    {
        $this->assertSame(['49.205.9.9', false], $this->seen('49.205.9.9', ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https']));
    }

    public function test_two_customers_behind_the_same_cloudflare_address_stay_separate_for_sign_in_limits(): void
    {
        $this->assertNotSame(
            $this->seen('172.70.10.20', ['X-Forwarded-For' => '49.205.1.2'])[0],
            $this->seen('172.70.10.20', ['X-Forwarded-For' => '106.51.7.8'])[0],
        );
    }

    public function test_today_without_cloudflare_a_direct_visitor_keeps_their_address(): void
    {
        $this->assertSame('49.205.1.2', $this->seen('49.205.1.2')[0]);
    }
}
